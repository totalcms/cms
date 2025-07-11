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

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$results = '';

		if ($request->getMethod() === 'POST') {
			$post = (array)$request->getParsedBody();

			if (isset($post['twig'])) {
				try {
					$results = $this->twigEngine->renderString($post['twig']);
				} catch (\Throwable $e) {
					$results = sprintf('<div class="error"><pre><code>%s</code></pre></div>', htmlspecialchars($e->getMessage()));
				}
			}
		}

		return $this->twigRenderer->template($response, 'admin/playground.twig', [
			'url' => [
				'path'  => $request->getUri()->getPath(),
				'query' => $request->getUri()->getQuery(),
				'page'  => 'playground',
			],
			'results'  => $results,
			'postData' => $request->getMethod() === 'POST' ? (array)$request->getParsedBody() : [],
		]);
	}
}