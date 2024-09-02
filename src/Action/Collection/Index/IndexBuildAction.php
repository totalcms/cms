<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

final class IndexBuildAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private IndexBuilder $service
	) {}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface {
		$collection = $args['collection'];
		$index      = $this->service->buildIndex($collection);

		return $this->renderer->jsonItem($response, $index, new IndexTransformer());
	}
}
