<?php
namespace Dynamics\Controllers;

use Dynamics\Dynamics;
use Dynamics\Settings;
use \Monolog\Logger;
use \Slim\Http\Response;

//---------------------------------------------------------------------------------
// Templates Controller
//---------------------------------------------------------------------------------
class TemplatesController extends Controller
{
    private $templateDir;

    public function __construct(Settings $settings, Logger $logger)
    {
        parent::__construct($settings, $logger);
        $this->templateDir = realpath(__DIR__."/../Templates");
    }

    //-------------------
    // Templates
    //-------------------
    public function getTemplate(string $type, string $template) : array
    {
        $this->logger->debug("TemplatesController - getTemplate");

        $templatePath = implode(DIRECTORY_SEPARATOR, [
            $this->templateDir,
            ucwords($type),
            "${template}.html"
        ]);
        $this->logger->debug("Getting template $templatePath ".$this->templateDir);
        return array("template" => Dynamics::readFile($templatePath));
    }
}
