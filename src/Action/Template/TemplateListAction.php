<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Renderer\JsonRenderer;

final class TemplateListAction
{
    private JsonRenderer $renderer;
    private TemplateLister $templateLister;

    public function __construct(JsonRenderer $renderer, TemplateLister $service)
    {
        $this->renderer       = $renderer;
        $this->templateLister = $service;
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
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = $params['filter'] ?? 'all';

        switch ($filter) {
            case 'reserved':
                $templates = $this->templateLister->listReservedTemplates();
                break;
            case 'custom':
                $templates = $this->templateLister->listCustomTemplates();
                break;
            default:
                $templates = $this->templateLister->listAllTemplates();
        }

        return $this->renderer->jsonCollection($response, $templates, new TemplateMetaTransformer());
    }
}
