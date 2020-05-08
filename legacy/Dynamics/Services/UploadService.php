<?php
namespace Dynamics\Services;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Slim\Http\Response;
use \Monolog\Logger;
use Dynamics\Settings;
use Dynamics\Controllers\CollectionsController;

// Service for uploading assets to Dynamics objects
class UploadService extends Service
{
    public function __construct(Settings $settings, Request $request, Logger $logger)
    {
        parent::__construct($settings, $request, $logger);
        $this->controller = new CollectionsController($this->settings, $this->logger);
    }

    //-------------------
    // CRUD Assets
    //-------------------
    public function saveFile(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("UploadService - saveAsset");
        $this->setCollection($request);
        $id    = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $type  = $request->getAttribute('type');
        $data  = $request->getParsedBody();
        $files = $request->getUploadedFiles();

        if (!array_key_exists($type, $files)) {
            $this->logger->error("UploadService/saveFile - Unable to locate upload asset");
            return $response->withJson("UploadService/saveFile - Unable to locate upload asset");
        }

        $data['type'] = $type;
        $file = $files[$type];
        $this->logger->debug("UploadService - Controller saveFile ($type)", [$id, $field, $file, $data]);
        return $response->withJson($this->controller->saveFile($id, $field, $file, $data));
    }
    public function deleteFile(Request $request, Response $response, array $params) : Response
    {
        $this->setCollection($request);
        $id    = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $file  = $request->getAttribute('file');
        return $response->withJson($this->controller->deleteFile($id, $field, $file));
    }
    public function getFiles(Request $request, Response $response, array $params) : Response
    {
        $this->setCollection($request);
        $id    = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $file  = $request->getAttribute('file');
        return $response->withJson('do something here');
    }
}
