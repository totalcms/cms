<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Admin 404 Not Found Action.
 *
 * Displays a custom 404 page for admin routes that don't exist.
 */
readonly class Admin404Action
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		return $this->twigRenderer->template(
			$response->withStatus(404),
			'admin/404.twig',
			[
				'url' => [
					'path'  => $request->getUri()->getPath(),
					'query' => $request->getUri()->getQuery(),
					'page'  => '404',
				],
			]
		);
	}
}
