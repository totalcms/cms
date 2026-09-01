<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

readonly class IndexBuildAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private IndexBuilder $service,
		private IndexRepository $storage,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$built      = $this->service->buildIndex($collection);

		// Read back rather than returning the build's value: above the
		// streaming threshold buildIndex() writes the file and hands back an
		// empty IndexData, so this endpoint reported an empty index after a
		// perfectly successful rebuild of a large collection.
		$index = $this->storage->fetchIndex($collection) ?? $built;

		return $this->renderer->jsonItem($response, $index, new IndexTransformer());
	}
}
