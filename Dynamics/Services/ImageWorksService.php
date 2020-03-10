<?php
namespace Dynamics\Services;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Slim\Http\Response;
use \Monolog\Logger;
use Dynamics\Settings;
use Dynamics\Controllers\ImageWorksController;

// Service for the ImageWorks API
class ImageWorksService extends Service
{
    public function __construct(Settings $settings, Request $request, Logger $logger)
    {
        parent::__construct($settings, $request, $logger);
        $this->controller = new ImageWorksController($this->settings, $this->logger);
    }

    //-------------------
    // ImageWorks
    //-------------------
    public function getImageByField(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("ImageWorksService - getImageByField");
        $this->setCollection($request);

        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');

        $query = $request->getQueryParams();

        $image = $this->controller->getImageByField($id, $field, $query);
        if ($image->getStatusCode() === 404) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        return $image;
    }
    public function getImage(Request $request, Response $response, array $params) : Response
    {
        $this->logger->debug("ImageWorksService - getDynamicsImage");
        $this->setCollection($request);

        $id = $request->getAttribute('id');
        $field = $request->getAttribute('field');
        $file  = $request->getAttribute('file');

        $query = $request->getQueryParams();

        $image = $this->controller->getImage($id, $field, $file, $query);
        if ($image->getStatusCode() === 404) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        return $image;
    }
}
