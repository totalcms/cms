<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * License middleware.
 */
final class StandardLicenseMiddleware implements MiddlewareInterface
{
    /**
     * Invoke middleware.
     *
     * @param ServerRequestInterface $request The request
     * @param RequestHandlerInterface $handler The handler
     *
     * @return ResponseInterface The response
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // TODO: Create a license object that actually check for a license
        $isStandardLicensed = true;

        // If valid Lite license call next and return.
        /** @phpstan-ignore-next-line */
        if ($isStandardLicensed) {
            return $handler->handle($request);
        }

        // Set response headers before giving it to error callback
        // $response = (new ResponseFactory())->createResponse(401, 'Unauthorized');
        // $response->getBody()->write('Invalid License Found');
        // return $response;
    }
}
