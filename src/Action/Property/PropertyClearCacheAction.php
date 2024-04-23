<?php

namespace TotalCMS\Action\Property;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;

final class PropertyClearCacheAction
{
    public function __construct(
        private JsonRenderer $renderer,
        private PropertyCacheCleaner $service,
    ) {
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
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $deleted = $this->service->deletePropertyCache($args['collection'], $args['id'], $args['property']);

        if ($deleted === false) {
            $response = $response->withStatus(500);
        }

        return $this->renderer->json($response, ['deleted' => $deleted]);
    }
}
