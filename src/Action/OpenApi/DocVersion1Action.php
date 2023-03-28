<?php

namespace TotalCMS\Action\OpenApi;

use TotalCMS\Renderer\TemplateRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Yaml\Yaml;

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

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // Path to the yaml file
        $yamlFile = __DIR__ . '/../../../resources/api/totalcms-api.yaml';

        $viewData = [
            'spec' => json_encode(Yaml::parseFile($yamlFile)),
        ];

        return $this->templateRenderer->template($response, 'doc/swagger.php', $viewData);
    }
}
