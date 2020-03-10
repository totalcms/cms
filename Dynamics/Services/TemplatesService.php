<?php
namespace Dynamics\Services;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Slim\Http\Response;
use \Monolog\Logger;
use Dynamics\Settings;
use Dynamics\Controllers\TemplatesController;

// Service for the Templates API
class TemplatesService extends Service
{
    public function __construct(Settings $settings, Request $request, Logger $logger)
    {
        parent::__construct($settings, $request, $logger);
        $this->controller = new TemplatesController($this->settings, $this->logger);
    }

    //-------------------
    // Templates
    //-------------------
    public function getTemplate(Request $request, Response $response, array $params) : Response
    {
        $type = $request->getAttribute('type');
        $template = $request->getAttribute('template');
        return $response->withJson($this->controller->getTemplate($type, $template));
    }
}
