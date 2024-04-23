<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Renderer\JsonRenderer;

final class ObjectDeleteAction
{
    public function __construct(
        private JsonRenderer $renderer,
        private ObjectRemover $remover
    ) {
        $this->remover  = $remover;
        $this->renderer = $renderer;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $deleted = $this->remover->deleteObject($args['collection'], $args['id']);

        if ($deleted === false) {
            return $response->withStatus(500);
        }

        return $this->renderer->json($response, ['deleted' => $deleted]);
    }
}
