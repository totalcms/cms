<?php
namespace Dynamics\Services;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Slim\Http\Response;
use \Monolog\Logger;
use Dynamics\Settings;
use Dynamics\Controllers\ImportController;

// Service for the Import API
class ImportService extends Service
{
    public function __construct(Settings $settings, Request $request, Logger $logger)
    {
        parent::__construct($settings, $request, $logger);
        $this->controller = new ImportController($this->settings, $this->logger);
    }

    //-------------------
    // Import
    //-------------------
    public function importCSV(Request $request, Response $response, array $params) : Response
    {
        $collection = $request->getAttribute('collection');
        $files      = $request->getUploadedFiles();
        $file       = $files["csv"];
        return $response->withJson($this->controller->importCSV($collection, $file));
    }

    public function importLink(Request $request, Response $response, array $params) : Response
    {
        $collection = $request->getAttribute('collection');
        $data       = $request->getParsedBody();
        return $response->withJson($this->controller->importLink($collection, $data));
    }
}
