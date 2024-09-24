<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final class AdminImageworksAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		return $this->twigRenderer->template($response, 'admin/imageworks.twig');
	}
}
