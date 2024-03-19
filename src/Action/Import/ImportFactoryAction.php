<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\FactoryImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportFactoryAction
{
    private JsonRenderer $renderer;
    private FactoryImporter $importer;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param FactoryImporter $importer Factory import service
     */
    public function __construct(JsonRenderer $renderer, FactoryImporter $importer)
    {
        $this->renderer  = $renderer;
        $this->importer  = $importer;
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
        $collection = $request->getAttribute('collection');
        $quantity   = $request->getQueryParams()['quantity'] ?? 1;
        $defs       = $request->getParsedBody() ?? [];

        $importCount = $this->importer->import($collection, $quantity, $defs);

        return $this->renderer->json($response, ['import_count' => $importCount]);
    }
}
