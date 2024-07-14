<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final class AdminIndexAction
{
	private TwigRenderer $twigRenderer;

	public function __construct(TwigRenderer $twigRenderer)
	{
		$this->twigRenderer = $twigRenderer;
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response
	): ResponseInterface {
		return $this->twigRenderer->template($response, 'admin/index.twig');
	}
}
