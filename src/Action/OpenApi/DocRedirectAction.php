<?php

declare(strict_types=1);

namespace TotalCMS\Action\OpenApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Action.
 */
class DocRedirectAction
{
	public function __construct(private readonly RedirectRenderer $redirectRenderer)
	{
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->redirectRenderer->redirectFor($response, 'api-docs');
	}
}
