<?php
namespace Dynamics\Controllers;

use Dynamics\Settings;
use \Monolog\Logger;

//---------------------------------------------------------------------------------
// Buffer Controller
//---------------------------------------------------------------------------------
class BufferController
{
    private $logger;
    private $settings;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    public function start() : void
    {
        ob_start();
    }

    public function getBuffer() : string
    {
        $buffer = ob_get_clean();
        $this->start();
        return $buffer;
    }

    public function processMacros() : string
    {
        $buffer = $this->getBuffer();
        $macros = new \Dynamics\Macros($this->settings, $this->logger);
        $buffer = $macros->globalMacros($buffer);
        $buffer = $macros->dynamicPropertyMacros($buffer);
        $buffer = $macros->objectPropertyMacros($buffer);
        return $buffer;
    }

    public function generateObjectMeta() : void
    {
        // $buffer = ob_get_clean();
        // ob_start();

        // $dynamicob = preg_replace("/<title>.*<\/title>/", "<title>$blogpost->title</title>", $dynamicob);

        // $meta = "";
        // $meta .= $totalblog->meta_description($blogpost);
        // $meta .= $totalblog->meta_facebook($blogpost);
        // $meta .= $totalblog->meta_twitter($blogpost, "%id=twitter%");
        // $meta .= $totalblog->meta_google($blogpost);

        // $dynamicob = str_replace("</"."head>", $meta."</"."head>", $dynamicob);

        // $macros = new ObjectMacros();
        // echo $macros->replace($buffer, $prefix);
    }
}
