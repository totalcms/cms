<?php

namespace TotalCMS\Action\OpenApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TemplateRenderer;

/**
 * Action.
 */
final class DocVersion1Action
{
    private TemplateRenderer $templateRenderer;

    public function __construct(TemplateRenderer $templateRenderer)
    {
        $this->templateRenderer = $templateRenderer;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Path to the OpenAPI json file
        $jsonFile = __DIR__ . '/../../../resources/api/totalcms-api.json';

        $viewData = [
            'spec' => file_get_contents($jsonFile),
        ];

        return $this->templateRenderer->template($response, 'doc/swagger.php', $viewData);
    }
}
