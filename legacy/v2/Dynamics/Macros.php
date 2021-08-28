<?php
namespace Dynamics;

use \Monolog\Logger;
use Dynamics\Settings;
use Dynamics\Controllers\CollectionsController;

//---------------------------------------------------------------------------------
// Total CMS Macros
//---------------------------------------------------------------------------------
class Macros
{
    private $settings;
    private $logger;
    private $controller;

    const GLOBAL_MATCH  = '/%cms(\w+)\(\s*([\w-]+)\s*,*\s*({.*})*\s*\)%/';
    const DYNAMIC_MATCH = '/%cms(\w+)\(\s*([\w-]+)\s*,\s*([\w-]+)\s*,*\s*({.*})*\s*\)%/';
    const FULL_MATCH    = '/%cms(\w+)\(\s*([\w-]+)\s*,\s*([\w-]+)\s*,\s*([\w-]+)\s*,*\s*({.*})*\s*\)%/';

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings   = $settings;
        $this->logger     = $logger;
        $this->controller = new CollectionsController($settings, $logger);
        $this->objects    = [];
        // Add prefix?
    }

    private function parseSettings(string $settings) : array
    {
        //! TODO This really need to convert the string into an array
        return json_encode($settings);
    }

    public function globalMacros(string $buffer) : string
    {
        // %cmsText(id)%
        // %cmsText(id,{option:true})%
        if (preg_match_all(self::GLOBAL_MATCH, $buffer, $matches)) {
            $macros    = $matches[0];
            $functions = array_map("strtolower", $matches[1]);  // cmsText -> text()
            $ids       = $matches[2];
            $options   = $matches[3];

            foreach ($functions as $index => $function) {
                if (method_exists($this, $function)) {
                    $macro    = $macros[$index];
                    $this->logger->debug("globalMacros: $macro");

                    $settings = []; //$this->parseSettings($options[$index]??"");
                    $object   = [ "id" => $ids[$index] ];
                    $buffer = $this->$function($macro, $buffer, $object, $settings);
                }
            }
        }
        return $buffer;
    }

    public function dynamicPropertyMacros(string $buffer) : string
    {
        // %cmsText(collection,property)%
        // %cmsText(collection,property,{option:true})%
        if (preg_match_all(self::DYNAMIC_MATCH, $buffer, $matches)) {
            $macros      = $matches[0];
            $functions   = $matches[1];
            $collections = $matches[2];
            $properties  = $matches[3];
            $options     = $matches[4];

            foreach ($functions as $index => $function) {
                if (method_exists($this, $function)) {
                    $macro = $macros[$index];
                    $id    = $_GET["id"];
                    $this->logger->debug("dynamicPropertyMacros: $macro for $id");
                    $settings = []; //$options[$index];
                    $object   = [
                        "collection" => $collections[$index],
                        "id"         => $id,
                        "property"   => $properties[$index],
                    ];
                    $buffer = $this->$function($macro, $buffer, $object, $settings);
                }
            }
        }
        return $buffer;
    }

    public function objectPropertyMacros(string $buffer) : string
    {
        // %cmsText(collection,id,property)%
        // %cmsText(collection,id,property,{option:true})%
        if (preg_match_all(self::FULL_MATCH, $buffer, $matches)) {
            $macros      = $matches[0];
            $functions   = $matches[1];
            $collections = $matches[2];
            $ids         = $matches[3];
            $properties  = $matches[4];
            $options     = $matches[5];

            foreach ($functions as $index => $function) {
                if (method_exists($this, $function)) {
                    $macro = $macros[$index];
                    $id    = $ids[$index];
                    $this->logger->debug("objectPropertyMacros: $macro for $id");
                    $settings = []; //$options[$index];
                    $object   = [
                        "collection" => $collections[$index],
                        "id"         => $id,
                        "property"   => $properties[$index],
                    ];
                    $buffer = $this->$function($macro, $buffer, $object, $settings);
                }
            }
        }
        return $buffer;
    }

    private function findObject(array $object, string $default) : array
    {
        // Find the object inside of the CMS
        $collection = $object["collection"] ?? $default;
        $id         = $object["id"] ?? $_GET["id"];

        if (!isset($this->objects[$collection][$id])) {
            // Cache the object so that we don't fetch it many times
            $this->controller->setCollection($collection);
            $this->objects[$collection][$id] = $this->controller->getObject($id);
        }
        return $this->objects[$collection][$id];
    }

    private function text(string $macro, string $buffer, array $object, array $settings)
    {
        $type      = "text";
        $property  = $object["property"] ?? $type;
        $dynObject = $this->findObject($object, $type);
        $value     = isset($dynObject[$property]) ? $dynObject[$property] : "";
        if ($value) {
            return str_replace($macro, $value, $buffer);
        }
        $this->logger->warn("Unable to process macro: $macro", $object);
        return $buffer;
    }

    private function image(string $macro, string $buffer, array $object, array $settings)
    {
        // return imageworks URL or full <img> tag?
        // alt tag?
        // exif data?
    }

    private function date(string $macro, string $buffer, array $object, array $settings)
    {
        // return formated date
    }

    private function file(string $macro, string $buffer, array $object, array $settings)
    {
        // return file URL w/ download option
    }

    private function gallery(string $macro, string $buffer, array $object, array $settings)
    {
        // return first/last/random imageworks url for gallery image
        // alt tag?
    }

    private function depot(string $macro, string $buffer, array $object, array $settings)
    {
        // return file URL w/ download option from depot
    }
}
