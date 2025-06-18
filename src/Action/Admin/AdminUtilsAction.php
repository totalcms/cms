<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\TwigEngine;
use TotalCMS\Renderer\TwigRenderer;

final class AdminUtilsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TwigEngine $twigEngine,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$page    = $args['page'] ?? 'index';
		$results = '';

		if ($request->getMethod() === 'POST') {
			$post = (array)$request->getParsedBody();

			switch ($page) {
				case 'twig-playground':
					if (isset($post['twig'])) {
						try {
							$results = $this->twigEngine->renderString($post['twig']);
						} catch (\Throwable $e) {
							$results = sprintf('<div class="error"><pre><code>%s</code></pre></div>', htmlspecialchars($e->getMessage()));
						}
					}
					break;
				case 'pretty-url-builder':
					// nothing to do yet
					break;
			}
		}

		return $this->twigRenderer->template($response, 'admin/utils.twig', [
			'page' => $page,
			'url'  => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'utils',
			],
			'results' => $results,
		]);
	}
}
