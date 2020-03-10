<?php
namespace Dynamics\Services;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Slim\Http\Response;
use \Monolog\Logger;
use Dynamics\Settings;

// Base Service class
class Service
{
    protected $logger;
    protected $settings;
    protected $controller;

    public function __construct(Settings $settings, Request $request, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    protected function setCollection(Request $request) : void
    {
        $collection = $request->getAttribute('collection');
        $this->logger->debug("setCollection to $collection");
        $this->controller->setCollection($collection);
    }
}
