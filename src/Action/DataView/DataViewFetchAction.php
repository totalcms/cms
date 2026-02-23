<?php

declare(strict_types=1);

namespace TotalCMS\Action\DataView;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Renderer\JsonRenderer;

readonly class DataViewFetchAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DataViewFetcher $dataViewFetcher,
		private DataViewBuilder $dataViewBuilder,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$viewId = $args['id'];

		if (!$this->dataViewFetcher->dataExists($viewId)) {
			$this->dataViewBuilder->buildView($viewId);
		}

		$data = $this->dataViewFetcher->getViewData($viewId);

		return $this->renderer->json($response, $data);
	}
}
