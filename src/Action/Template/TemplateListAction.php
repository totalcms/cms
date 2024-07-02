<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Renderer\RawRenderer;

final class TemplateListAction
{
    private RawRenderer $renderer;
    private TemplateLister $templateLister;

    public function __construct(RawRenderer $renderer, TemplateLister $service)
    {
        $this->renderer       = $renderer;
        $this->templateLister = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<string,string> $args The routing arguments
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

        $json = json_encode($templates);
        if ($json === false) {
            throw new \RuntimeException('json_encode error: ' . json_last_error_msg());
        }

        return $this->renderer->render($response, $json);
    }
}
