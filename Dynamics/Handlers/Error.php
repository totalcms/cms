<?php
namespace Dynamics\Handlers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Monolog\Logger;
use Slim\Http\Body;

class Error
{
    protected $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(Request $request, Response $response, \Throwable $exception)
    {
        // Log the message
        $this->logger->critical($exception->getMessage());

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
}
