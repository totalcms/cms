<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\TwigRenderer;

final class AdminPlaygroundAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TwigEngine $twigEngine,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke( ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		// Handle POST requests for rendering Twig (JSON response)
		$render = '';

		if ($request->getMethod() === 'POST') {
			$post = (array)$request->getParsedBody();

			if (isset($post['twig'])) {
				$render = $this->twigEngine->renderString($post['twig']);
			}
		}

		// Handle GET requests for the playground page
		return $this->twigRenderer->template($response, 'admin/playground.twig', [
			'url' => [
				'path'    => $request->getUri()->getPath(),
				'query'   => $request->getUri()->getQuery(),
				'page'    => 'playground',
				'id'      => $args['id'] ?? '',
				'results' => $render,
			],
		]);
	}
}