<?php

declare(strict_types=1);

namespace TotalCMS\Action\DataView;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\JsonRenderer;

readonly class DataViewRebuildAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectFetcher $objectFetcher,
		private DataViewBuilder $viewBuilder,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$viewId = $args['id'];

		// Verify view exists
		$this->objectFetcher->fetchObject(DataViewData::COLLECTION_ID, $viewId);

		// Try immediate build
		$this->viewBuilder->buildView($viewId);

		// Re-fetch to get updated metadata
		$updated = $this->objectFetcher->fetchObject(DataViewData::COLLECTION_ID, $viewId);

		return $this->renderer->json($response, $updated->toArray());
	}
}
