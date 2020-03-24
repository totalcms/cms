<?php

use App\Middleware\CorsMiddleware;
use App\Middleware\LiteLicenseMiddleware;
use App\Middleware\SessionMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Selective\Config\Configuration;
use Selective\SameSiteCookie\SameSiteCookieConfiguration;
use Selective\SameSiteCookie\SameSiteCookieMiddleware;
use Selective\SameSiteCookie\SameSiteSessionMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Views\TwigMiddleware;

return function (App $app) {
    $container = $app->getContainer();

    // Parse json, form data and xml
    $app->addBodyParsingMiddleware();

    // CORS Middleware
    $app->add(CorsMiddleware::class);

    // Add routing middleware
    // Register the same site cookie middleware
    $configuration = new SameSiteCookieConfiguration();
    $app->add(new SameSiteCookieMiddleware($configuration));
    $app->add(new SameSiteSessionMiddleware($configuration));

    $app->add(ValidationExceptionMiddleware::class);
    $app->add(TwigMiddleware::class);

    $app->add(SessionMiddleware::class);
    $app->addRoutingMiddleware();
    $app->add(BasePathMiddleware::class);

    $app->add(LiteLicenseMiddleware::class);
    
    // Add error handler middleware
    $settings = $container->get(Configuration::class)->getArray('error_handler_middleware');
    $displayErrorDetails = (bool) $settings['display_error_details'];
    $logErrors = (bool) $settings['log_errors'];
    $logErrorDetails = (bool) $settings['log_error_details'];
    
    $app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);
};
