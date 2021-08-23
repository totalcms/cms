<?php

use App\Middleware\CorsMiddleware;
use App\Middleware\LiteLicenseMiddleware;
use App\Middleware\UrlGeneratorMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;

return function (App $app) {
    // Parse json, form data and xml
    $app->addBodyParsingMiddleware();

    // CORS Middleware
    $app->add(CorsMiddleware::class);

    $app->add(LiteLicenseMiddleware::class);
    $app->add(ValidationExceptionMiddleware::class);
    $app->add(UrlGeneratorMiddleware::class);
    $app->addRoutingMiddleware();
    $app->add(BasePathMiddleware::class);
    $app->add(ErrorMiddleware::class);
};
