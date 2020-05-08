<?php
namespace Dynamics\Controllers;

use Dynamics\Settings;
use \Monolog\Logger;
use Dynamics\Dynamics;
use Dynamics\Component\DynamicObject;
use \Slim\Http\Response;

//---------------------------------------------------------------------------------
// ImageWorks Controller
//---------------------------------------------------------------------------------
class ImageWorksController extends Controller
{
    public function __construct(Settings $settings, Logger $logger)
    {
        parent::__construct($settings, $logger);
        $this->collectionsController = new CollectionsController($this->settings, $this->logger);
    }

    public function setCollection(string $collection) : void
    {
        $this->collectionsController->setCollection($collection);
    }

    //-------------------
    // ImageWorks
    //-------------------
    public function getImageByField(string $id, string $field, array $params) : Response
    {
        $this->logger->debug("ImageWorksController - getImageByField");

        $dyn = $this->collectionsController->dynObject($id);
        return $dyn->getImageByField($field, $params);
    }

    public function getImage(string $id, string $field, string $file, array $params) : Response
    {
        $this->logger->debug("ImageWorksController - getImage");

        $dyn = $this->collectionsController->dynObject($id);
        return $dyn->getImage($field, $file, $params);
    }
}
