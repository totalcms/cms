<?php

declare(strict_types=1);

namespace TotalCMS\Action\Report;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Report\Service\ReportExporter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Export a collection report as JSON with selected fields.
 */
readonly class ReportJsonAction
{
	public function __construct(
		private ReportExporter $reportExporter,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];

		try {
			$parsed = $this->reportExporter->parseParams($request->getQueryParams());
		} catch (\InvalidArgumentException $e) {
			throw new HttpBadRequestException($request, $e->getMessage());
		}

		$data     = $this->reportExporter->exportJsonData($collection, $parsed['fields'], $parsed['options']);
		$response = $response->withHeader('Content-Disposition', sprintf('attachment; filename="report-%s.json"', $collection));

		return $this->jsonRenderer->json($response, $data);
	}
}
