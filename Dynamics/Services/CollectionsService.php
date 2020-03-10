<?php
namespace Dynamics\Services;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Slim\Http\Response;
use \Monolog\Logger;
use Dynamics\Settings;
use Dynamics\Controllers\CollectionsController;

// Service for collections of Dynamics objects
class CollectionsService extends Service
{
    public function __construct(Settings $settings, Request $request, Logger $logger)
    {
        parent::__construct($settings, $request, $logger);
        $this->controller = new CollectionsController($this->settings, $this->logger);
    }

    //-------------------
    // Collections
    //-------------------
    public function getCollections(Request $request, Response $response, array $params) : Response
    {
        return $response->withJson($this->controller->getCollections());
    }

    //-------------------
    // Schema
    //-------------------
    public function getSchema(Request $request, Response $response, array $params) : Response
    {
        $this->setCollection($request);
        return $response->withJson($this->controller->getSchema());
    }
    public function saveSchema(Request $request, Response $response, array $params) : Response
    {
        $body = $request->getParsedBody();
        $this->setCollection($request);
        return $response->withJson($this->controller->saveSchema($body));
    }

    //-------------------
    // Index
    //-------------------
    public function getIndex(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("Service - getIndex");
        $this->setCollection($request);
        return $response->withJson($this->controller->getIndex());
    }
    public function rebuildIndex(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("Service - rebuildIndex");
        $this->setCollection($request);
        return $response->withJson($this->controller->rebuildIndex());
    }

    //-------------------
    // Single Object
    //-------------------
    public function getObject(Request $request, Response $response, array $params) : Response
    {
        $id = $request->getAttribute('id');
        $this->setCollection($request);
        return $response->withJson($this->controller->getObject($id));
    }
    public function exists(Request $request, Response $response, array $params) : Response
    {
        $id = $request->getAttribute('id');
        $this->setCollection($request);
        return $response->withJson($this->controller->exists($id));
    }
    public function saveObject(Request $request, Response $response, array $params) : Response
    {
        $body = $request->getParsedBody();
        $this->setCollection($request);
        return $response->withJson($this->controller->saveObject($body));
    }
    public function updateObject(Request $request, Response $response, array $params) : Response
    {
        $id   = $request->getAttribute('id');
        $body = $request->getParsedBody();
        $this->setCollection($request);
        return $response->withJson($this->controller->updateObject($id, $body));
    }
    public function deleteObject(Request $request, Response $response, array $params) : Response
    {
        $id = $request->getAttribute('id');
        $this->setCollection($request);
        return $response->withJson($this->controller->deleteObject($id));
    }
    public function saveField(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("Service - saveField");
        $this->setCollection($request);
        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $data  = $request->getParsedBody();
        $files = $request->getUploadedFiles();
        if (array_key_exists($field, $files)) {
            // If the proper file for this field is provided, save the file
            $file  = $files[$field];
            $this->logger->debug("Service - Controller saveFile", [$id, $field, $file, $data]);
            return $response->withJson($this->controller->saveFile($id, $field, $file, $data));
        }
        return $response->withJson($this->controller->saveField($id, $field, $data));
    }
    public function deleteFile(Request $request, Response $response, array $params) : Response
    {
        $this->setCollection($request);
        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $file = $request->getAttribute('file');
        return $response->withJson($this->controller->deleteFile($id, $field, $file));
    }
    public function updateField(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("Service - updateField");
        $this->setCollection($request);
        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $data  = $request->getParsedBody();
        return $response->withJson($this->controller->updateField($id, $field, $data));
    }
    public function updateFile(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("Service - updateFile");
        $this->setCollection($request);
        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $file = $request->getAttribute('file');
        $data  = $request->getParsedBody();
        return $response->withJson($this->controller->updateFile($id, $field, $file, $data));
    }
    public function clearFieldCache(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("Service - clearFieldCache");
        $this->setCollection($request);
        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        return $response->withJson($this->controller->clearFieldCache($id, $field));
    }
}
