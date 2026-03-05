<?php

declare(strict_types=1);

namespace TotalCMS\Action\Report;

use Nyholm\Psr7\Stream;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Report\Service\ReportExporter;

/**
 * Export a collection report as CSV with selected fields and deck expansion.
 */
readonly class ReportCsvAction
{
	public function __construct(
		private ReportExporter $reportExporter,
		private SessionInterface $session,
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

		$result = $this->reportExporter->exportCsvString($collection, $parsed['fields'], $parsed['options']);

		if (count($result['errors']) > 0) {
			$flash = $this->session->getFlash();
			$flash->add('warning', sprintf(
				'%d object(s) were skipped during report export due to data mismatches. Check the logs for more information.',
				count($result['errors'])
			));
		}

		$response = $response->withHeader('Content-Type', 'text/csv')
			->withHeader('Content-Disposition', sprintf('attachment; filename="report-%s.csv"', $collection));

		return $response->withBody(Stream::create($result['csv']));
	}
}
