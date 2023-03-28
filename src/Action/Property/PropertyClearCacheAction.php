<?php

namespace TotalCMS\Action\Collection\Object\Property;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PropertyClearCacheAction
{
    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('PropertyClearCacheAction');

        return $response;
    }
}
