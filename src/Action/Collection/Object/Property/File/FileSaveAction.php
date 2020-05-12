<?php

namespace App\Action\Collection\Object\Property\File;

use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FileSaveAction
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
        $response->getBody()->write('FileSaveAction');
        return $response;
    }
}
