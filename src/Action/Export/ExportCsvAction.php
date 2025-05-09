<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use League\Csv\Writer;

final class ExportCsvAction
{
	public function __construct(
		private ObjectExporter $objectExporter,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface
	{
		$collection = $args['collection'];
		$objects    = $this->objectExporter->exportAllObjectsForCSv($collection);

		$csv = Writer::createFromString('');
		$csv->insertAll($objects);
		$csvData = $csv->toString();

		$response = $response->withHeader('Content-Type', 'text/csv')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s-export.csv"', $collection));

		return $response->withBody(Stream::create($csvData));
	}
}
