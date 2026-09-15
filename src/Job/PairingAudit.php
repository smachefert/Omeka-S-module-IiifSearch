<?php declare(strict_types=1);

namespace IiifSearch\Job;

use IiifSearch\Stdlib\PagePairer;
use IiifSearch\Stdlib\PageSource;
use IiifSearch\Stdlib\XmlMediaClassifier;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Dry-run audit of the page-to-image pairing for items having OCR data.
 *
 * Produces a CSV under files/iiif-search-audit/ listing per item: media counts
 * (pdf, alto, hocr, image), the pairing method chosen, its coverage and any
 * warning. Lets operators review the cascade decision across a fonds before
 * launching a writing run.
 */
class PairingAudit extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?? (OMEKA_PATH . '/files');
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $pairingMode = (string) ($this->getArg('pairing_mode')
            ?: $settings->get('iiifsearch_alto_pairing_mode', 'auto'));
        $itemIdsArg = (string) $this->getArg('item_ids');

        $auditDir = $basePath . '/iiif-search-audit';
        if (!is_dir($auditDir) && !@mkdir($auditDir, 0775, true)) {
            $logger->err(new Message(
                'Cannot create audit directory "%s".', // @translate
                $auditDir
            ));
            return;
        }
        $csvPath = $auditDir . '/' . date('Ymd_His') . '.csv';
        $fp = @fopen($csvPath, 'w');
        if (!$fp) {
            $logger->err(new Message(
                'Cannot open audit file "%s" for writing.', // @translate
                $csvPath
            ));
            return;
        }
        fputcsv($fp, ['item_id', 'pdf_count', 'alto_count', 'hocr_count', 'image_count', 'method', 'coverage', 'warnings']);

        $sql = <<<'SQL'
            SELECT DISTINCT `item_id`
            FROM `media`
            WHERE (`media_type` IN (:pdf_media_type) AND `extension` = :pdf_extension)
                OR `media_type` LIKE 'image/%'
                OR `media_type` LIKE 'application/%xml%'
                OR `media_type` LIKE 'text/xml%'
                OR `media_type` = 'text/html'
            SQL;
        $bind = [
            'pdf_media_type' => ['application/pdf', 'text/pdf'],
            'pdf_extension' => 'pdf',
        ];
        $types = [
            'pdf_media_type' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
            'pdf_extension' => \Doctrine\DBAL\ParameterType::STRING,
        ];
        if ($itemIdsArg) {
            $ids = array_filter(array_map('intval', preg_split('/[^0-9]+/', $itemIdsArg) ?: []));
            if ($ids) {
                $sql .= ' AND `item_id` IN (' . implode(',', $ids) . ')';
            }
        }
        $sql .= ' ORDER BY `item_id` ASC';
        $itemIds = $connection->executeQuery($sql, $bind, $types)->fetchFirstColumn();

        $classifier = new XmlMediaClassifier();
        $pairer = new PagePairer($basePath);

        $total = count($itemIds);
        $logger->notice(new Message(
            '%1$d items will be audited; pairing mode: %2$s.', // @translate
            $total, $pairingMode
        ));

        foreach ($itemIds as $id) {
            if ($this->shouldStop()) {
                $logger->warn(new Message(
                    'Audit stopped after %1$d items.', // @translate
                    (int) array_search($id, $itemIds, true)
                ));
                break;
            }
            try {
                $item = $api->read('items', ['id' => (int) $id])->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                continue;
            }

            $pdfCount = 0;
            $altoCount = 0;
            $hocrCount = 0;
            $imageCount = 0;
            $altoMedias = [];
            foreach ($item->media() as $media) {
                $mt = (string) $media->mediaType();
                if (strtok($mt, '/') === 'image' && $media->hasOriginal()) {
                    ++$imageCount;
                }
                if ((string) $media->extension() === 'pdf'
                    && ($mt === 'application/pdf' || $mt === 'text/pdf')
                ) {
                    ++$pdfCount;
                }
                if ($classifier->isXmlLikeMedia($media)) {
                    $cls = $classifier->classifyMedia($media, $basePath);
                    if ($cls === XmlMediaClassifier::TYPE_ALTO) {
                        ++$altoCount;
                        $altoMedias[] = $media;
                    } elseif ($cls === XmlMediaClassifier::TYPE_HOCR) {
                        ++$hocrCount;
                    }
                }
            }

            $method = 'n/a';
            $coverage = 'n/a';
            $warnings = '';
            if (count($altoMedias) > 1) {
                $sources = [];
                $i = 0;
                foreach ($altoMedias as $a) {
                    ++$i;
                    $sources[] = new PageSource(
                        $i,
                        $a,
                        $basePath . '/original/' . $a->filename(),
                        (string) $a->source(),
                        'alto'
                    );
                }
                $pairing = $pairer->pair($item, $sources, $pairingMode);
                $method = $pairing->method;
                $coverage = number_format($pairing->coverage, 2);
                $warnings = implode(' | ', $pairing->warnings);
            }

            fputcsv($fp, [
                (int) $id,
                $pdfCount,
                $altoCount,
                $hocrCount,
                $imageCount,
                $method,
                $coverage,
                $warnings,
            ]);
        }
        fclose($fp);

        $logger->notice(new Message(
            'Pairing audit written to %s.', // @translate
            $csvPath
        ));
    }
}
