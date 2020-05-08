<?php

use App\Middleware\CorsMiddleware;
use App\Middleware\LiteLicenseMiddleware;
use App\Middleware\SessionMiddleware;
use App\Middleware\UrlGeneratorMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Selective\SameSiteCookie\SameSiteCookieConfiguration;
use Selective\SameSiteCookie\SameSiteCookieMiddleware;
use Selective\SameSiteCookie\SameSiteSessionMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use Slim\Views\TwigMiddleware;

return function (App $app) {
    // Parse json, form data and xml
    $app->addBodyParsingMiddleware();

    // CORS Middleware
    $app->add(CorsMiddleware::class);

    // Add routing middleware
    // Register the same site cookie middleware
    $configuration = new SameSiteCookieConfiguration();
    $app->add(new SameSiteCookieMiddleware($configuration));
    $app->add(new SameSiteSessionMiddleware($configuration));

    $app->add(LiteLicenseMiddleware::class);
    $app->add(ValidationExceptionMiddleware::class);
    $app->add(SessionMiddleware::class);
    $app->add(UrlGeneratorMiddleware::class);
    $app->addRoutingMiddleware();
    $app->add(TwigMiddleware::class);
    $app->add(BasePathMiddleware::class);
    $app->add(ErrorMiddleware::class);
};
