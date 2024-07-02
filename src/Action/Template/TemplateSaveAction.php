<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Renderer\RawRenderer;

final class TemplateSaveAction
{
    private RawRenderer $renderer;
    private TemplateSaver $service;

    /**
     * The constructor.
     *
     * @param RawRenderer $renderer The renderer
     * @param TemplateSaver $service Template save service
     */
    public function __construct(RawRenderer $renderer, TemplateSaver $service)
    {
        $this->renderer = $renderer;
        $this->service  = $service;
    }

    /**
     * Invokable Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<string,string> $args The routing arguments
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $content = (string)$request->getBody();
        $name    = $args['template'];

        // ! This is a horrible hack purely so that I can test this action.
        // ! The pest slim post function does not allow for sending a plain text body with a post request.
        if ($this->isJson($content)) {
            $content = json_decode($content, true)[0];
        }

        $templateData = $this->service->saveTemplate($name, $content);

        return $this->renderer->render($response, $templateData->contents);
    }

    private function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
