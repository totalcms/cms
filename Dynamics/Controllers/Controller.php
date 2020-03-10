<?php
namespace Dynamics\Controllers;

use Dynamics\Settings;
use \Monolog\Logger;

//---------------------------------------------------------------------------------
// Base Controller
//---------------------------------------------------------------------------------
class Controller
{
    protected $settings;
    protected $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
    }
}
