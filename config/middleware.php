<?php

use Selective\BasePath\BasePathMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use TotalCMS\Middleware\CorsMiddleware;
use TotalCMS\Middleware\LiteLicenseMiddleware;

return function (App $app) {
    $app->addBodyParsingMiddleware();
    $app->add(CorsMiddleware::class);
    $app->add(LiteLicenseMiddleware::class);
    $app->add(ValidationExceptionMiddleware::class);
    $app->addRoutingMiddleware();
    $app->add(BasePathMiddleware::class);
    $app->add(ErrorMiddleware::class);
};
