<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectExporter;

final class ExportJsonAction
{
	public function __construct(
		private ObjectExporter $objectExporter,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface
	{
		$collection = $args['collection'];
		$objects    = $this->objectExporter->exportAllObjects($collection);

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s-export.json"', $collection));

		$jsonData = json_encode($objects);

		return $response->withBody(Stream::create($jsonData));
	}
}
