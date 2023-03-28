<?php

namespace App\Action\Template;

use App\Domain\Template\Service\TemplateSaver;
use App\Renderer\RawRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
     * @param array $args The routing arguments
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $content      = (string)$request->getBody();
        $name         = $args['template'];
        $templateData = $this->service->saveTemplate($name, $content);

        return $this->renderer->render($response, $templateData->contents);
    }
}
