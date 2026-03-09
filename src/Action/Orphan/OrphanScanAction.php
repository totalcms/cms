<?php

namespace TotalCMS\Action\Orphan;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Orphan\Service\OrphanScanner;
use TotalCMS\Renderer\JsonRenderer;

readonly class OrphanScanAction
{
	public function __construct(
		private OrphanScanner $scanner,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params     = $request->getQueryParams();
		$collection = $params['collection'] ?? null;

		if (is_string($collection) && $collection !== '') {
			$report = $this->scanner->scanCollection($collection);
		} else {
			$report = $this->scanner->scanAll();
		}

		return $this->renderer->json($response, $report->toArray());
	}
}
