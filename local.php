<?php

require_once 'dynamics.php';

$dynamics = array();
$dynamics['settings'] = new Dynamics\Settings();

$loggerSettings = $dynamics['settings']->get('logger');
$logger = new Monolog\Logger($loggerSettings['name']);
$logger->pushProcessor(new Monolog\Processor\UidProcessor());
$logger->pushHandler(new Monolog\Handler\RotatingFileHandler(
    $loggerSettings['path'],
    $loggerSettings['rotate'],
    $loggerSettings['level']
));
Monolog\ErrorHandler::register($logger);

return [$dynamics, $logger];
