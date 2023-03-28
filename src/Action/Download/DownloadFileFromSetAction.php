<?php

namespace TotalCMS\Action\Download;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DownloadFileFromSetAction
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
        $response->getBody()->write('DownloadFileFromSetAction');

        return $response;
    }
}
