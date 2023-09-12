<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateRemover;
use TotalCMS\Renderer\RawRenderer;

final class TemplateDeleteAction
{
    private RawRenderer $renderer;
    private TemplateRemover $service;

    /**
     * The constructor.
     *
     * @param RawRenderer $renderer The renderer
     * @param TemplateRemover $service Template save service
     */
    public function __construct(RawRenderer $renderer, TemplateRemover $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Invokable Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $deleted = $this->service->deleteTemplate($args['template']);

        if ($deleted === false) {
            return $response->withStatus(500);
        }

        return $response;
    }
}
