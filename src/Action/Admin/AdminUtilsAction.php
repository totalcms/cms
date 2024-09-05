<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final class AdminUtilsAction
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
		$page = $args['page'] ?? 'index';

		return $this->twigRenderer->template($response, 'admin/utils.twig', [
			'page' => $page,
			'url'  => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'utils',
			],
		]);
	}
}
