<?php

namespace TotalCMS\Action\Export;

use League\Csv\Writer;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectExporter;

readonly class ExportCsvAction
{
	public function __construct(
		private ObjectExporter $objectExporter,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$objects    = $this->objectExporter->exportAllObjectsForCSv($collection);

		$csv = Writer::createFromString('');
		$csv->insertAll($objects);
		$csvData = $csv->toString();

		$response = $response->withHeader('Content-Type', 'text/csv')
			->withHeader('Content-Disposition', sprintf('attachment; filename="collection-%s.csv"', $collection));

		return $response->withBody(Stream::create($csvData));
	}
}
