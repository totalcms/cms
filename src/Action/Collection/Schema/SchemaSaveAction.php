<?php

namespace App\Action\Collection\Schema;

use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SchemaSaveAction
{
    /**
     * Action
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Make sure that we cannot create schemas with reserved names (blog, text, gallery, etc)
        $response->getBody()->write('SchemaSaveAction');
        return $response;
    }
}
