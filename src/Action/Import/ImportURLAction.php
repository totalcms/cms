<?php

namespace App\Action\Import;

use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ImportURLAction
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
        $response->getBody()->write('ImportURLAction');
        return $response;
    }
}
