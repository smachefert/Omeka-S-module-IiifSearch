<?php

namespace IiifSearch\Controller;

use Omeka\Mvc\Exception\NotFoundException;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class SearchController extends AbstractActionController
{
    public function indexAction()
    {
        $id = $this->params('id');
        if (empty($id)) {
            throw new NotFoundException;
        }

        $q = $this->params()->fromQuery('q');
        if (!strlen($q)) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel([
                'status' => 'error',
                'message' => $this->translate('Missing or empty query.'), // @translate
            ]);
        }

        // Exception is automatically thrown by api.
        $item = $this->api()->read('items', $id)->getContent();

        $iiifSearch = $this->viewHelpers()->get('iiifSearch');
        $searchResponse = $iiifSearch($item);

        if (!$searchResponse) {
            $this->getResponse()->setStatusCode(\Zend\Http\Response::STATUS_CODE_400);
            return new JsonModel([
                'status' => 'error',
                'message' => sprintf($this->translate('Search is not supported for resource #%d.'), $id), // @translate
            ]);
        }

        return $this->jsonLd($searchResponse);
    }

    public function annotationListAction()
    {
        // TODO Implement annotation-list action.
        $this->getResponse()->setStatusCode(\Zend\Http\Response::STATUS_CODE_501);
        return new JsonModel([
            'status' => 'error',
            'message' => $this->translate('Direct request to annotation-list is not implemented.'), // @translate
        ]);
    }
}
