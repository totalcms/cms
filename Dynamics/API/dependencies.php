<?php
use Dynamics\Services\CollectionsService;
use Dynamics\Services\ImageWorksService;
use Dynamics\Services\TemplatesService;
use Dynamics\Services\ImportService;
use Dynamics\Services\UploadService;
use Monolog\Handler\RotatingFileHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Http\Body;

// Container configuration
$container = $app->getContainer();

// CSRF guard
$container['csrf'] = function ($c) {
    $guard = new \Slim\Csrf\Guard();
    $guard->setFailureCallable(
        function ($request, $response, $next) {
            $request = $request->withAttribute("csrf_status", false);
            return $next($request, $response);
        }
    );
    return $guard;
};

// monolog
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Monolog\Logger($settings['name']);
    $logger->pushProcessor(new Monolog\Processor\UidProcessor());
    $logger->pushHandler(new RotatingFileHandler($settings['path'], $settings['rotate'], $settings['level']));
    return $logger;
};
Monolog\ErrorHandler::register($container['logger']);

// error handlers
$container['errorHandler'] = function ($container) {
    return function ($request, $response, $exception) use ($container) {
        $container['logger']->critical($exception->getMessage());

        // create a JSON error string for the Response body
        $body = json_encode([
            'error' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new Body(fopen('php://temp', 'r+')))
            ->write($body);

        // Could not get this to work
        // return new Dynamics\Handlers\Error($request, $response, $exception, $container['logger']);
    };
};
$container['phpErrorHandler'] = function ($container) {
    return function ($request, $response, $exception) use ($container) {
        if ($response && $exception) {
            $container['logger']->critical($exception->getMessage());

            // create a JSON error string for the Response body
            $body = json_encode([
                'error' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace()
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new Body(fopen('php://temp', 'r+')))
                ->write($body);
        }
        return new Dynamics\Handlers\Error($container['logger']);
    };
    // return function ($container) {
    //     return new Dynamics\Handlers\Error($container['logger']);
    // };
};
$container['notFoundHandler'] = function ($container) {
    return function ($request, $response) use ($container) {
        if ($response) {
            return $container['response']
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/html')
                ->write('API Not Found');
        }
        return new Dynamics\Handlers\Error($container['logger']);
    };
};

// controller init
$container[CollectionsService::class] = function ($container) use ($settings) {
    return new CollectionsService($settings, $container['request'], $container['logger']);
};
$container[TemplatesService::class] = function ($container) use ($settings) {
    return new TemplatesService($settings, $container['request'], $container['logger']);
};
$container[ImageWorksService::class] = function ($container) use ($settings) {
    return new ImageWorksService($settings, $container['request'], $container['logger']);
};
$container[ImportService::class] = function ($container) use ($settings) {
    return new ImportService($settings, $container['request'], $container['logger']);
};
$container[UploadService::class] = function ($container) use ($settings) {
    return new UploadService($settings, $container['request'], $container['logger']);
};
