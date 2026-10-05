<?php declare(strict_types=1);

namespace IiifSearch\Job;

use IiifSearch\Stdlib\AltoProvenance;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Delete medias the module is known to have created in the past, with strict
 * provenance rules:
 *  - ALTO  : the file must carry the IiifSearch provenance marker
 *            (see ToAltoConverter::stampProvenance()). No filename fallback.
 *  - pdf2xml : source ends with ".{itemId}.pdf2xml.xml".
 *  - tsv / tsv;by-word : source ends with ".{itemId}.tsv" or
 *            ".{itemId}.by-word.tsv".
 *
 * The deletion goes through raw DBAL (Connection::delete) to bypass the
 * Doctrine event listener that calls Omeka's Local storage delete inside the
 * flush transaction. Without that bypass, an unlink() failure would close the
 * EntityManager for the rest of the job.
 *
 * The original file is unlinked best-effort after the row is dropped: the
 * module no longer stores OCR derivatives as medias by design, and the
 * canonical sources live under files/alto/, files/iiif-search/ and
 * files/pdf2xml/, populated by the Extract OCR job (which the caller may
 * dispatch right after the cleanup). User-uploaded medias are never touched,
 * whatever their mime type.
 */
class CleanupMedia extends AbstractJob
{
    const SQL_LIMIT = 50;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $basePath;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('iiifsearch/cleanup-media/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);

        $this->connection = $services->get('Omeka\Connection');
        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        $targets = (array) ($this->getArg('targets') ?: []);
        $targets = array_values(array_intersect(['tsv', 'pdf2xml', 'alto'], $targets));
        if (!$targets) {
            $this->logger->warn(new Message('No format selected; nothing to do.')); // @translate
            return;
        }
        $this->logger->info(new Message(
            'Cleanup targets: %1$s.', // @translate
            implode(', ', $targets)
        ));

        $totalChecked = 0;
        $totalDeleted = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($targets as $target) {
            [$mediaTypes, $suffixes] = $this->targetCriteria($target);
            if (!$mediaTypes) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($mediaTypes), '?'));
            // Omeka stores storage_id and extension separately; the actual
            // filename under files/original/ is "{storage_id}.{extension}".
            // Compose it row by row.
            $sql = 'SELECT id, item_id, media_type, source, storage_id, extension'
                . ' FROM media WHERE media_type IN (' . $placeholders . ')'
                . ' ORDER BY id ASC';

            $stmt = $this->connection->executeQuery($sql, $mediaTypes);
            while (($row = $stmt->fetchAssociative()) !== false) {
                if ($this->shouldStop()) {
                    $this->logger->warn(new Message(
                        'Cleanup stopped: %1$d checked, %2$d deleted, %3$d skipped, %4$d errors.', // @translate
                        $totalChecked, $totalDeleted, $totalSkipped, $totalErrors
                    ));
                    return;
                }
                ++$totalChecked;

                if (!$this->matchesProvenance($target, $row, $suffixes)) {
                    ++$totalSkipped;
                    continue;
                }

                $ok = $this->deleteMediaRow((int) $row['id']);
                if (!$ok) {
                    ++$totalErrors;
                    continue;
                }

                $this->unlinkOriginal($row);
                ++$totalDeleted;

                if ($totalDeleted % self::SQL_LIMIT === 0) {
                    $this->logger->info(new Message(
                        'Progress: %1$d checked, %2$d deleted, %3$d skipped, %4$d errors.', // @translate
                        $totalChecked, $totalDeleted, $totalSkipped, $totalErrors
                    ));
                }
            }
        }

        $this->logger->notice(new Message(
            'Cleanup done: %1$d medias checked, %2$d deleted, %3$d skipped (not module-created), %4$d errors.', // @translate
            $totalChecked, $totalDeleted, $totalSkipped, $totalErrors
        ));

        // Dispatch the OCR re-extraction job only now so the queue worker does
        // not pull them concurrently. The flag and the base URI are forwarded
        // from the config-form handler.
        if ($this->getArg('rerun_extract')) {
            try {
                /** @var \Omeka\Job\Dispatcher $dispatcher */
                $dispatcher = $this->getServiceLocator()->get(\Omeka\Job\Dispatcher::class);
                $extractJob = $dispatcher->dispatch(\IiifSearch\Job\ExtractOcr::class, [
                    'mode' => 'missing',
                    'base_uri' => (string) ($this->getArg('rerun_base_uri') ?: ''),
                    'item_ids' => '',
                    'query' => '',
                ]);
                $this->logger->notice(new Message(
                    'Dispatched follow-up Extract OCR job #%1$d in mode "missing".', // @translate
                    $extractJob->getId()
                ));
            } catch (\Throwable $e) {
                $this->logger->err(new Message(
                    'Could not dispatch the follow-up Extract OCR job: %1$s', // @translate
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * @return array{0:string[],1:string[]} [mediaTypes, suffixes]
     */
    protected function targetCriteria(string $target): array
    {
        if ($target === 'tsv') {
            return [
                ['text/tab-separated-values', 'text/tab-separated-values;by-word'],
                ['.tsv', '.by-word.tsv'],
            ];
        }
        if ($target === 'pdf2xml') {
            // Early versions of the module attached pdf2xml derivatives with a
            // generic XML mime — the precise pdf2xml mime was not declared yet.
            // Include those legacy mimes here and confirm the actual format via
            // content sniffing in matchesProvenance.
            return [
                [
                    'application/vnd.pdf2xml+xml',
                    'text/xml',
                    'application/xml',
                    'application/octet-stream',
                ],
                ['.pdf2xml.xml'],
            ];
        }
        if ($target === 'alto') {
            return [
                ['application/alto+xml'],
                [],
            ];
        }
        return [[], []];
    }

    /**
     * Apply the strict module-provenance rule for the target type.
     */
    protected function matchesProvenance(string $target, array $row, array $suffixes): bool
    {
        $itemId = (int) $row['item_id'];
        $source = (string) ($row['source'] ?? '');

        if ($target === 'alto') {
            $filename = $this->rowFilename($row);
            if ($filename === '') {
                return false;
            }
            return AltoProvenance::isModuleAlto(
                $this->basePath . '/original/' . $filename
            );
        }

        if ($target === 'pdf2xml') {
            // Canonical mime: trust unconditionally — the module is the sole
            // producer of pdf2xml.
            if ((string) ($row['media_type'] ?? '') === 'application/vnd.pdf2xml+xml') {
                return true;
            }
            // Legacy generic XML mimes: content-sniff the file root element. A
            // non-pdf2xml XML (item-uploaded mods, dc, tei…) is left alone.
            $filename = $this->rowFilename($row);
            if ($filename === '') {
                return false;
            }
            $filepath = $this->basePath . '/original/' . $filename;
            return $this->isPdf2xmlFile($filepath);
        }

        if ($source === '' || $itemId <= 0) {
            return false;
        }
        foreach ($suffixes as $suffix) {
            $needle = '.' . $itemId . $suffix;
            if (substr($source, -strlen($needle)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop the database row via raw DBAL to avoid the storage event listener
     * that runs inside Doctrine's flush transaction.
     */
    protected function deleteMediaRow(int $mediaId): bool
    {
        try {
            $this->connection->delete('media', ['id' => $mediaId]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Failed to delete media row #%1$d: %2$s', // @translate
                $mediaId, $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Unlink the original file best-effort. Derivatives (thumbnails) are left
     * alone: they belong to the row that has just been dropped and Omeka's cli
     * thumbnail rebuild will reclaim them. Any unlink failure is logged and
     * never propagated — the canonical state is the database, the row is
     * already gone.
     */
    protected function unlinkOriginal(array $row): void
    {
        $mediaId = (int) $row['id'];
        $filename = $this->rowFilename($row);
        if ($filename === '') {
            return;
        }
        $originalPath = $this->basePath . '/original/' . $filename;
        if (!file_exists($originalPath)) {
            return;
        }
        if (!@unlink($originalPath)) {
            $this->logger->warn(new Message(
                'Could not unlink "%1$s" after deleting media #%2$d.', // @translate
                $originalPath, $mediaId
            ));
        }
    }

    /**
     * Reconstruct the on-disk filename from a DBAL row. Omeka stores the
     * storage id and extension in two columns; the file under files/original/
     * is "{storage_id}.{extension}". A row missing either value falls back to
     * an empty string so the caller can skip it.
     */
    protected function rowFilename(array $row): string
    {
        $storageId = (string) ($row['storage_id'] ?? '');
        if ($storageId === '') {
            return '';
        }
        $extension = (string) ($row['extension'] ?? '');
        return $extension === ''
            ? $storageId
            : $storageId . '.' . $extension;
    }

    /**
     * Probe an XML file via XMLReader for a top-level <pdf2xml> root. Bails out
     * at the first element node so the cost stays O(first-tag) regardless of
     * file size.
     */
    protected function isPdf2xmlFile(string $filepath): bool
    {
        if (!is_readable($filepath)) {
            return false;
        }
        $reader = new \XMLReader();
        if (!@$reader->open($filepath, null, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return false;
        }
        $isPdf2xml = false;
        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                $isPdf2xml = $reader->localName === 'pdf2xml';
                break;
            }
        } finally {
            $reader->close();
        }
        return $isPdf2xml;
    }
}
