<?php

namespace TotalCMS\Action\OpenApi;

use TotalCMS\Renderer\RedirectRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Action.
 */
final class DocIndexAction
{
    private RedirectRenderer $redirectRenderer;

    public function __construct(RedirectRenderer $redirectRenderer)
    {
        $this->redirectRenderer = $redirectRenderer;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirectRenderer->redirectFor($response, 'docs');
    }
}
