<?php declare(strict_types=1);

namespace IiifSearch\Controller;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class SearchController extends AbstractActionController
{
    public function indexAction()
    {
        // TODO The output must be a valid json iiif response.

        $id = $this->params('id');
        if (empty($id)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Missing or empty query.'), // @translate
            ]);
        }

        $q = (string) $this->params()->fromQuery('q');
        if (!strlen($q)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Missing or empty query.'), // @translate
            ]);
        }

        // Exception is automatically thrown by api.
        $item = null;
        if (is_numeric($id)) {
            try {
                $item = $this->api()->read('items', $id)->getContent();
            } catch (\Throwable $e) {
                // See below.
            }
        } elseif (class_exists('CleanUrl\Module', false)) {
            $item = $this->viewHelpers()->get('getResourceFromIdentifier')($id);
        }

        if (empty($item)) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Resource not found or unavailable.'),  // @translate
            ]);
        }

        $iiifSearch = $this->viewHelpers()->get('iiifSearch');
        $searchResponse = $iiifSearch($item);

        if (!$searchResponse) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Search is not available for this resource.'), // @translate
            ]);
        }

        return $this->jsonLd($searchResponse);
    }

    /**
     * Serve a IIIF Presentation 2 AnnotationList built from a single ALTO page,
     * suitable for canvas otherContent in v2 manifests.
     */
    public function annotationListAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        // Route param :name accepts both "p1" and bare digits; normalize.
        $name = (string) $this->params('name');
        $pageIndex = ltrim($name, 'p');
        if ($pageIndex === '' || !ctype_digit($pageIndex)) {
            return $this->errorResponse(Response::STATUS_CODE_400, 'Invalid page name.'); // @translate
        }
        $pageIndex = (int) $pageIndex;
        // v2 canvas naming convention "p1" is 1-based; normalize to 0-based for
        // the ALTO splitter and downstream helpers.
        if (strpos($name, 'p') === 0) {
            $pageIndex = max(0, $pageIndex - 1);
        }

        $splitter = $this->viewHelpers()->get('xmlAltoSplitter');
        $path = $splitter($item, $pageIndex);
        if (!$path || !is_readable($path)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }

        $mtime = (int) filemtime($path);
        $etag = $this->buildEtag('annot2', $item->id(), $pageIndex, $mtime);
        if ($notModified = $this->respondNotModifiedIfMatch($etag, $mtime)) {
            return $notModified;
        }

        $annotations = $this->viewHelpers()->get('iiifAltoAnnotations2');
        $list = $annotations($item, $pageIndex, $path);
        if (!$list) {
            return $this->errorResponse(Response::STATUS_CODE_500, 'Unable to build annotation list.'); // @translate
        }

        $response = $this->getResponse();
        $this->addCacheHeaders($response->getHeaders(), $etag, $mtime, 3600);

        return $this->jsonLd($list);
    }

    public function index2Action()
    {
        $id = $this->params('id');
        if (empty($id)) {
            return $this->errorResponse(Response::STATUS_CODE_400, 'Missing or empty query.'); // @translate
        }

        $q = (string) $this->params()->fromQuery('q');
        if (!strlen($q)) {
            return $this->errorResponse(Response::STATUS_CODE_400, 'Missing or empty query.'); // @translate
        }

        $item = $this->resolveItem();
        if (empty($item)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found or unavailable.'); // @translate
        }

        $iiifSearch2 = $this->viewHelpers()->get('iiifSearch2');
        $searchResponse = $iiifSearch2($item);

        if (!$searchResponse) {
            return $this->errorResponse(Response::STATUS_CODE_400, 'Search is not available for this resource.'); // @translate
        }

        return $this->jsonLd($searchResponse);
    }

    /**
     * Serve a single page extracted from a multipage ALTO XML document.
     */
    public function altoPageAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        // Public page numbering is 1-based; internal index is 0-based.
        $pageNumber = (int) $this->params('page');
        if ($pageNumber < 1) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }
        $pageIndex = $pageNumber - 1;
        $splitter = $this->viewHelpers()->get('xmlAltoSplitter');
        $path = $splitter($item, $pageIndex);
        if (!$path || !is_readable($path)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }

        $mtime = (int) filemtime($path);
        $etag = $this->buildEtag('alto', $item->id(), $pageIndex, $mtime);
        if ($notModified = $this->respondNotModifiedIfMatch($etag, $mtime)) {
            return $notModified;
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/xml+alto; charset=utf-8');
        $headers->addHeaderLine('Content-Length', (string) filesize($path));
        $this->addCacheHeaders($headers, $etag, $mtime, 3600);
        $response->setContent((string) file_get_contents($path));
        return $response;
    }

    /**
     * Serve a single page of hOCR.
     *
     * Resolution cascade:
     *  1. Native hOCR media attached to the item at the positional index N-1.
     *  2. Per-page ALTO (resolved by xmlAltoSplitter) converted on the fly
     *     via the alto_to_hocr.xsl stylesheet.
     *  3. 404.
     */
    public function hocrPageAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        $pageNumber = (int) $this->params('page');
        if ($pageNumber < 1) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No hOCR page available.'); // @translate
        }
        $pageIndex = $pageNumber - 1;

        $services = $this->getEvent()->getApplication()->getServiceManager();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');

        // 1. Native hOCR media at the matching positional index.
        $itemFileResources = $this->viewHelpers()->get('itemFileResources');
        $hocrs = [];
        foreach ($itemFileResources($item) as $resource) {
            if ((string) $resource->mediaType() === 'text/vnd.hocr+html') {
                $hocrs[] = $resource;
            }
        }
        $target = $hocrs[$pageIndex] ?? null;
        if ($target) {
            $filename = (string) $target->filename();
            $path = $filename !== '' ? $basePath . '/original/' . $filename : '';
            if ($path !== '' && is_readable($path)) {
                $mtime = (int) filemtime($path);
                $etag = $this->buildEtag('hocr', $item->id(), $pageIndex, $mtime);
                if ($notModified = $this->respondNotModifiedIfMatch($etag, $mtime)) {
                    return $notModified;
                }
                $response = $this->getResponse();
                $headers = $response->getHeaders();
                $headers->addHeaderLine('Content-Type', 'text/vnd.hocr+html; charset=utf-8');
                $headers->addHeaderLine('Content-Length', (string) filesize($path));
                $this->addCacheHeaders($headers, $etag, $mtime, 3600);
                $response->setContent((string) @file_get_contents($path));
                return $response;
            }
        }

        // 2. Convert from the per-page ALTO via XSLT.
        $splitter = $this->viewHelpers()->get('xmlAltoSplitter');
        $altoPath = $splitter($item, $pageIndex);
        if (!$altoPath || !is_readable($altoPath)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No hOCR page available.'); // @translate
        }

        $altoMtime = (int) filemtime($altoPath);
        $etag = $this->buildEtag('hocr-alto', $item->id(), $pageIndex, $altoMtime);
        if ($notModified = $this->respondNotModifiedIfMatch($etag, $altoMtime)) {
            return $notModified;
        }
        $hocrContent = $this->renderHocrFromAlto($altoPath);
        if ($hocrContent === null) {
            return $this->errorResponse(Response::STATUS_CODE_500, 'Failed to render hOCR from ALTO.'); // @translate
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/vnd.hocr+html; charset=utf-8');
        $headers->addHeaderLine('Content-Length', (string) strlen($hocrContent));
        $this->addCacheHeaders($headers, $etag, $altoMtime, 3600);
        $response->setContent($hocrContent);
        return $response;
    }

    /**
     * Apply the bundled alto_to_hocr.xsl stylesheet to the given ALTO file and
     * return the resulting hOCR HTML, or null on failure.
     */
    protected function renderHocrFromAlto(string $altoPath): ?string
    {
        $xsltPath = dirname(__DIR__, 2) . '/data/xsl/alto_to_hocr.xsl';
        if (!is_readable($xsltPath)) {
            return null;
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = @$dom->load($altoPath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return null;
        }
        $xsl = new \DOMDocument();
        if (!@$xsl->load($xsltPath, LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return null;
        }
        $proc = new \XSLTProcessor();
        $proc->setSecurityPrefs(XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK);
        if (!@$proc->importStyleSheet($xsl)) {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return null;
        }
        $out = @$proc->transformToXml($dom);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $out === false ? null : $out;
    }

    /**
     * Serve a IIIF AnnotationPage built from a single ALTO page (supplementing
     * motivation, suitable for Mirador text overlay).
     */
    public function annotationPageAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        // Public page numbering is 1-based; internal index is 0-based.
        $pageNumber = (int) $this->params('page');
        if ($pageNumber < 1) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }
        $pageIndex = $pageNumber - 1;
        $splitter = $this->viewHelpers()->get('xmlAltoSplitter');
        $path = $splitter($item, $pageIndex);
        if (!$path || !is_readable($path)) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'No ALTO page available.'); // @translate
        }

        $mtime = (int) filemtime($path);
        $etag = $this->buildEtag('annot', $item->id(), $pageIndex, $mtime);
        if ($notModified = $this->respondNotModifiedIfMatch($etag, $mtime)) {
            return $notModified;
        }

        $annotations = $this->viewHelpers()->get('iiifAltoAnnotations');
        $page = $annotations($item, $pageIndex, $path);
        if (!$page) {
            return $this->errorResponse(Response::STATUS_CODE_500, 'Unable to build annotation page.'); // @translate
        }

        $response = $this->getResponse();
        $this->addCacheHeaders($response->getHeaders(), $etag, $mtime, 3600);

        return $this->jsonLd($page);
    }

    protected function resolveItem(): ?\Omeka\Api\Representation\ItemRepresentation
    {
        $id = $this->params('id');
        if (empty($id)) {
            return null;
        }
        if (is_numeric($id)) {
            try {
                return $this->api()->read('items', $id)->getContent();
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (class_exists('CleanUrl\Module', false)) {
            $resource = $this->viewHelpers()->get('getResourceFromIdentifier')($id);
            if ($resource && $resource->resourceName() === 'items') {
                return $resource;
            }
        }
        return null;
    }

    protected function errorResponse(int $code, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($code);
        return new JsonModel([
            'status' => 'error',
            'message' => $this->translate($message),
        ]);
    }

    /**
     * Build a stable ETag from a kind tag and arbitrary scalar parts.
     */
    protected function buildEtag(string $kind, ...$parts): string
    {
        return '"' . $kind . '-' . substr(md5(implode('|', $parts)), 0, 16) . '"';
    }

    /**
     * Inspect If-None-Match / If-Modified-Since on the current request and
     * return a configured 304 response when the client's cached copy is still
     * valid. Returns null otherwise (the caller continues normally).
     */
    protected function respondNotModifiedIfMatch(string $etag, int $mtime): ?\Laminas\Http\Response
    {
        $request = $this->getRequest();
        $ifNoneMatch = $request->getHeader('If-None-Match');
        $ifModifiedSince = $request->getHeader('If-Modified-Since');

        $matchesEtag = $ifNoneMatch && trim($ifNoneMatch->getFieldValue()) === $etag;
        $matchesDate = $ifModifiedSince
            && ($since = strtotime($ifModifiedSince->getFieldValue()))
            && $since >= $mtime;

        if (!$matchesEtag && !$matchesDate) {
            return null;
        }

        $response = $this->getResponse();
        $response->setStatusCode(Response::STATUS_CODE_304);
        // RFC 7232: a 304 must include validators if they would have been sent
        // on a 200, so the client can refresh its freshness window.
        $headers = $response->getHeaders();
        $headers->addHeaderLine('ETag', $etag);
        $headers->addHeaderLine('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $headers->addHeaderLine('Cache-Control', 'public, max-age=3600');
        return $response;
    }

    protected function addCacheHeaders($headers, string $etag, int $mtime, int $maxAge): void
    {
        $headers->addHeaderLine('ETag', $etag);
        $headers->addHeaderLine('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $headers->addHeaderLine('Cache-Control', 'public, max-age=' . $maxAge);
    }
}
