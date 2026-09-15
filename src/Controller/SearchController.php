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

    public function annotationListAction()
    {
        // TODO Implement annotation-list action.
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_501);
        return new JsonModel([
            'status' => 'error',
            'message' => $this->translate('Direct request to annotation-list is not implemented.'), // @translate
        ]);
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

        $pageIndex = (int) $this->params('page');
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
     * Serve a IIIF AnnotationPage built from a single ALTO page (supplementing
     * motivation, suitable for Mirador text overlay).
     */
    public function annotationPageAction()
    {
        $item = $this->resolveItem();
        if (!$item) {
            return $this->errorResponse(Response::STATUS_CODE_404, 'Resource not found.'); // @translate
        }

        $pageIndex = (int) $this->params('page');
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
