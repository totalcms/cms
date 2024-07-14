<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

final class IndexBuildAction
{
	private JsonRenderer $renderer;
	private IndexBuilder $service;

	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param IndexBuilder $service Collection save service
	 */
	public function __construct(JsonRenderer $renderer, IndexBuilder $service)
	{
		$this->renderer = $renderer;
		$this->service  = $service;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface {
		return $this->renderer->jsonItem(
			$response,
			$this->service->buildIndex($args['collection']),
			new IndexTransformer()
		);
	}
}
