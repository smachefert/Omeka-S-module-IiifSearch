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

    /**
     * @var bool[] Presence of an ocr file by item id.
     */
    protected $ocrFiles = [];

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

        // The re-extraction requires pdftotext: without it, the job Extract OCR
        // fails immediately and the deleted ocr cannot be rebuilt, so nothing is
        // deleted.
        if ($this->getArg('rerun_extract')
            && !$services->get('Omeka\Cli')->getCommandPath('pdftotext')
        ) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(new Message(
                'The cleanup is followed by a new extraction, but the command-line utility pdftotext is not available, so the ocr cannot be rebuilt and nothing is deleted. Install the package poppler-utils or run the cleanup without the new extraction.' // @translate
            ));
            return;
        }

        $totalChecked = 0;
        $totalDeleted = 0;
        $totalSkipped = 0;
        $totalKept = 0;
        $totalErrors = 0;

        // DigitalObject shares the same storage_id / extension / media_type /
        // data layout as media. Process both tables when the module is active
        // so OCR derivatives attached either way are reclaimed by the same
        // pass.
        $tables = ['media'];
        if (class_exists('DigitalObject\Module', false)) {
            $tables[] = 'digital_object';
        }

        foreach ($targets as $target) {
            [$mediaTypes, $suffixes] = $this->targetCriteria($target);
            if (!$mediaTypes) {
                continue;
            }

            foreach ($tables as $table) {
                $placeholders = implode(',', array_fill(0, count($mediaTypes), '?'));
                // Omeka stores storage_id and extension separately; the actual
                // filename under files/original/ is "{storage_id}.{extension}".
                // Compose it row by row. digital_object has no item_id column
                // (link is via value), but the row is sufficient for the file
                // deletion path.
                $idColumns = $table === 'digital_object'
                    ? 'id, NULL AS item_id, media_type, source, storage_id, extension'
                    : 'id, item_id, media_type, source, storage_id, extension';
                $sql = 'SELECT ' . $idColumns
                    . ' FROM ' . $table
                    . ' WHERE media_type IN (' . $placeholders . ')'
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

                    // The media may be the only ocr of the item: the search uses
                    // it until the job Extract OCR creates the files.
                    $itemId = $table === 'digital_object'
                        ? $this->itemIdOfDigitalObject((int) $row['id'])
                        : (int) $row['item_id'];
                    if (!$itemId || !$this->hasOcrFile($itemId)) {
                        ++$totalKept;
                        continue;
                    }

                    $ok = $this->deleteRow($table, (int) $row['id']);
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
        }

        $this->logger->notice(new Message(
            'Cleanup done: %1$d medias checked, %2$d deleted, %3$d skipped (not module-created), %4$d errors.', // @translate
            $totalChecked, $totalDeleted, $totalSkipped, $totalErrors
        ));
        if ($totalKept) {
            $this->logger->warn(new Message(
                '%d medias were kept, because their item has no ocr file yet under files/iiif-search/ or files/alto/, so they are the only source of its search. Run the job Extract OCR in mode "missing" with the destination "file" (it requires pdftotext), then run the cleanup again.', // @translate
                $totalKept
            ));
        }

        // Dispatch the OCR re-extraction job only now so the queue worker does
        // not pull them concurrently. The flag and the base URI are forwarded
        // from the config-form handler.
        if ($this->getArg('rerun_extract')) {
            try {
                /** @var \Omeka\Job\Dispatcher $dispatcher */
                $dispatcher = $this->getServiceLocator()->get(\Omeka\Job\Dispatcher::class);
                $extractJob = $dispatcher->dispatch(\IiifSearch\Job\ExtractOcr::class, [
                    'mode' => 'missing',
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
     * Check if the item has an ocr file created by the job Extract OCR.
     */
    protected function hasOcrFile(int $itemId): bool
    {
        if (!isset($this->ocrFiles[$itemId])) {
            $this->ocrFiles[$itemId] = (bool) (glob($this->basePath . '/iiif-search/' . $itemId . '.*tsv')
                ?: (is_file($this->basePath . '/alto/' . $itemId . '.alto.xml')
                    || glob($this->basePath . '/alto/' . $itemId . '-*.alto.xml')));
        }
        return $this->ocrFiles[$itemId];
    }

    /**
     * A digital object has no item column: it is linked by a value of the item.
     */
    protected function itemIdOfDigitalObject(int $digitalObjectId): ?int
    {
        $itemId = $this->connection->fetchOne(
            'SELECT `value`.`resource_id` FROM `value` INNER JOIN `item` ON `item`.`id` = `value`.`resource_id` WHERE `value`.`value_resource_id` = ? LIMIT 1',
            [$digitalObjectId]
        );
        return $itemId ? (int) $itemId : null;
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
     * that runs inside Doctrine's flush transaction. Table is the table name
     * ("media" or "digital_object") — both share the same id column.
     */
    protected function deleteRow(string $table, int $id): bool
    {
        if (!in_array($table, ['media', 'digital_object'], true)) {
            return false;
        }
        // A media or a digital object is a resource (class table inheritance),
        // so the row of the table "resource" is deleted, that cascades to the
        // specific table and to the access status. Only the values block it, so
        // they are deleted first, with their annotations, that are resources.
        try {
            $this->connection->transactional(function (\Doctrine\DBAL\Connection $connection) use ($id): void {
                $annotationIds = $connection->fetchFirstColumn(
                    'SELECT `value_annotation_id` FROM `value` WHERE `resource_id` = ? AND `value_annotation_id` IS NOT NULL',
                    [$id]
                );
                $connection->executeStatement('DELETE FROM `value` WHERE `resource_id` = ?', [$id]);
                foreach ($annotationIds as $annotationId) {
                    $connection->executeStatement('DELETE FROM `value` WHERE `resource_id` = ?', [(int) $annotationId]);
                    $connection->executeStatement('DELETE FROM `resource` WHERE `id` = ?', [(int) $annotationId]);
                }
                $connection->executeStatement('DELETE FROM `resource` WHERE `id` = ?', [$id]);
            });
            return true;
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Failed to delete %1$s row #%2$d: %3$s', // @translate
                $table, $id, $e->getMessage()
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
