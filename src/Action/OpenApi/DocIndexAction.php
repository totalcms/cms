<?php

namespace TotalCMS\Action\OpenApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\RedirectRenderer;

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
