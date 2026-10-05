<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\XmlAltoSingle;
use Psr\Container\ContainerInterface;

class XmlAltoSingleFactory
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $helpers = $services->get('ViewHelperManager');
        $settings = $services->get('Omeka\Settings');

        return new XmlAltoSingle(
            $services->get('Omeka\Logger'),
            $helpers->get('fixUtf8'),
            $basePath,
            self::resolveXmlFixMode($settings->get('iiifsearch_xml_fix_mode'))
        );
    }

    /**
     * Map the new multi-checkbox value (array of "dom" / "regex" flags) back to
     * the legacy string the downstream helpers consume: 'no', 'dom', 'regex' or
     * 'all'. Strings are passed through unchanged so an admin editing the
     * setting directly via api or local.config.php still works.
     */
    public static function resolveXmlFixMode($value): string
    {
        if (is_string($value)) {
            return in_array($value, ['no', 'dom', 'regex', 'all'], true) ? $value : 'no';
        }
        if (!is_array($value)) {
            return 'no';
        }
        $dom = in_array('dom', $value, true);
        $regex = in_array('regex', $value, true);
        if ($dom && $regex) {
            return 'all';
        }
        if ($dom) {
            return 'dom';
        }
        if ($regex) {
            return 'regex';
        }
        return 'no';
    }
}
