<?php declare(strict_types=1);

namespace IiifSearch\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Delete the flat OCR files left by previous runs on items that no longer match
 * the current OCR policy (iiifsearch_extract_query_include /
 * iiifsearch_extract_query_exclude). Useful when an admin tightens the policy
 * after a wider initial OCR run (paintings or photographs that have been OCRed
 * by mistake).
 *
 * Scope per file kind:
 *  - files/iiif-search/{itemId}.full.tsv
 *  - files/iiif-search/{itemId}.by-word.tsv
 *  - files/alto/{itemId}.alto.xml
 *  - files/alto/{itemId}-page-NNN.alto.xml (tesseract output)
 *  - files/alto/{itemId}-*.alto.xml        (materialised pdf2xml/hocr/tei)
 *  - files/pdf2xml/{itemId}.pdf2xml.xml
 *
 * Only files whose item id can be parsed from the basename are considered;
 * unrelated files (no leading integer, foreign suffix) are skipped. The
 * filesystem under files/original/ is never touched — those are user uploads,
 * out of scope.
 */
class CleanupOcrFiles extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('iiifsearch/cleanup-ocr-files/job_' . $this->job->getId());
        $logger->addProcessor($referenceIdProcessor);

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        // Collect the candidate (itemId, path) pairs from each module
        // directory. Patterns are intentionally restrictive — anything that
        // does not match is left untouched.
        $patterns = [
            $basePath . '/iiif-search/' => '/^(\d+)\.(full|by-word)\.tsv$/',
            $basePath . '/alto/' => '/^(\d+)(?:[.-].+)?\.alto\.xml$/',
            $basePath . '/pdf2xml/' => '/^(\d+)\.pdf2xml\.xml$/',
        ];

        $candidates = []; // itemId => [paths]
        foreach ($patterns as $dir => $regex) {
            if (!is_dir($dir)) {
                continue;
            }
            $dh = @opendir($dir);
            if (!$dh) {
                continue;
            }
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (!preg_match($regex, $name, $m)) {
                    continue;
                }
                $itemId = (int) $m[1];
                if ($itemId <= 0) {
                    continue;
                }
                $candidates[$itemId][] = $dir . $name;
            }
            closedir($dh);
        }

        if (!$candidates) {
            $logger->notice(new Message(
                'No OCR file found under files/iiif-search, files/alto or files/pdf2xml.' // @translate
            ));
            return;
        }

        $itemIds = array_keys($candidates);
        $logger->info(new Message(
            'Found OCR files for %1$d items; resolving policy.', // @translate
            count($itemIds)
        ));

        // Resolve include / exclude policy in bulk via the search API; one call
        // each, no per-item loop. The set of "items that should keep their OCR"
        // is computed once and the difference is unlinked.
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $include = (string) $settings->get('iiifsearch_extract_query_include', '');
        $exclude = (string) $settings->get('iiifsearch_extract_query_exclude', '');

        $keep = $itemIds;
        if ($include !== '') {
            $q = [];
            parse_str($include, $q);
            if ($q) {
                $q['id'] = $itemIds;
                $allowed = $api->search('items', $q, ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                $allowed = array_map('intval', is_array($allowed) ? array_values($allowed) : []);
                $keep = array_values(array_intersect($keep, $allowed));
            }
        }
        if ($exclude !== '' && $keep) {
            $q = [];
            parse_str($exclude, $q);
            if ($q) {
                $q['id'] = $keep;
                $excluded = $api->search('items', $q, ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                $excluded = array_map('intval', is_array($excluded) ? array_values($excluded) : []);
                $keep = array_values(array_diff($keep, $excluded));
            }
        }

        $keepSet = array_flip($keep);
        $deletedFiles = 0;
        $deletedItems = 0;
        $errors = 0;

        foreach ($candidates as $itemId => $paths) {
            if ($this->shouldStop()) {
                $logger->warn(new Message(
                    'Cleanup stopped: %1$d files removed for %2$d items, %3$d errors.', // @translate
                    $deletedFiles, $deletedItems, $errors
                ));
                return;
            }
            if (isset($keepSet[$itemId])) {
                continue;
            }
            $deletedAny = false;
            foreach ($paths as $path) {
                if (@unlink($path)) {
                    ++$deletedFiles;
                    $deletedAny = true;
                } else {
                    ++$errors;
                    $logger->warn(new Message(
                        'Could not unlink "%1$s" (item #%2$d).', // @translate
                        $path, $itemId
                    ));
                }
            }
            if ($deletedAny) {
                ++$deletedItems;
            }
        }

        $logger->notice(new Message(
            'Cleanup of OCR files done: %1$d files removed for %2$d items, %3$d errors.', // @translate
            $deletedFiles, $deletedItems, $errors
        ));
    }
}
