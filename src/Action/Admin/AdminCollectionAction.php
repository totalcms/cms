<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminCollectionAction
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
		// /collections - index page
		// /collections/new - collection create form
		// /collections/{collection} - object list page
		// /collections/{collection}/edit - edit collection form
		// /collections/{collection}/add - edit collection form
		// /collections/{collection}/{id} - edit object form

		// Handle specific routes by setting expected args based on route name
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		$routeName    = $route?->getName() ?? '';

		if ($routeName === 'admin-collection-new') {
			$args['collection'] = 'new';
		} elseif ($routeName === 'admin-collection-edit') {
			$args['id'] = 'edit';
		}

		return $this->twigRenderer->template($response, 'admin/collection.twig', [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'params'     => $args,
				'page'       => 'collections',
				'collection' => $args['collection'] ?? '',
				'id'         => $args['id'] ?? '',
			],
		]);
	}
}
