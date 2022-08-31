<?php

namespace App\Action\Collection\Index;

use App\Domain\Index\Service\IndexBuilder;
use App\Renderer\JsonRenderer;
use App\Transformer\IndexTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class IndexBuildAction
{
    private JsonRenderer $renderer;
    private IndexBuilder $service;

    /**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param IndexBuilder $service Collection save service
     */
    public function __construct(JsonRenderer $renderer, IndexBuilder $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        return $this->renderer->jsonItem(
            $response,
            $this->service->buildIndex($args['collection']),
            new IndexTransformer()
        );
    }
}
