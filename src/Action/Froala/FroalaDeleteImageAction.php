<?php

namespace App\Action\Froala;

use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FroalaDeleteImageAction
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
        $response->getBody()->write('FroalaDeleteImageAction');
        return $response;
    }
}
