<?php

namespace TotalCMS\Action\Export;

use League\Csv\Writer;
use Nyholm\Psr7\Stream;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectExporter;

readonly class ExportCsvAction
{
	public function __construct(
		private ObjectExporter $objectExporter,
		private SessionInterface $session,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$result     = $this->objectExporter->exportAllObjectsForCSv($collection);
		$objects    = $result['data'];
		$errors     = $result['errors'];

		// If there were errors, set a flash message for the user
		if (count($errors) > 0) {
			$flash   = $this->session->getFlash();
			$message = sprintf(
				'%d object(s) were skipped during CSV export due to data mismatches. Check the logs for more information.',
				count($errors)
			);
			$flash->add('warning', $message);
		}

		$csv = Writer::fromString('');
		$csv->insertAll($objects);
		$csvData = $csv->toString();

		$response = $response->withHeader('Content-Type', 'text/csv')
			->withHeader('Content-Disposition', sprintf('attachment; filename="collection-%s.csv"', $collection));

		return $response->withBody(Stream::create($csvData));
	}
}
