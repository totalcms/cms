<?php

namespace App\Action\Import;

use App\Domain\Import\UrlImporter;
use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ImportUrlAction
{
    private UrlImporter $urlImporter;
    private Responder $responder;

    public function __construct(UrlImporter $urlImporter, Responder $responder)
    {
        $this->urlImporter = $urlImporter;
        $this->responder = $responder;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request The request
     * @param ResponseInterface $response The response
     *
     * @return ResponseInterface The response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $collection = (string)$request->getAttribute('collection');
        $body = (array)$request->getParsedBody();

        $properties = $body['properties'] ?? [];
        $link = $body['link'] ?? '';

        $this->urlImporter->import($collection, $link, $properties);

        return $this->responder->withJson($response, [
            'success' => true,
        ]);
    }
}
