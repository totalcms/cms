<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Domain\Twig\TwigEngine;

/**
 * Action.
 */
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
		$results = "";

		if ($request->getMethod() === 'POST') {
			$page = 'twig-playground';
			$post = (array)$request->getParsedBody();
			if (isset($post['twig'])) {
				$results = $this->twigEngine->renderString($post['twig']);
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
