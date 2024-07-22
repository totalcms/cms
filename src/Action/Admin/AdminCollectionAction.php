<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final class AdminCollectionAction
{
	private TwigRenderer $twigRenderer;

	public function __construct(TwigRenderer $twigRenderer)
	{
		$this->twigRenderer = $twigRenderer;
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

		return $this->twigRenderer->template($response, 'admin/collection.twig', [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'params'     => $args,
				'page'       => 'collections',
				'collection' => $args['collection'] ?? '',
				'id'         => $args['id'] ?? '',
			]
		]);
	}
}
