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

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$objects    = $this->objectExporter->exportAllObjects($collection);

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="collection-%s.json"', $collection));

		$jsonData = json_encode($objects);

		if ($jsonData === false) {
			$response = $response->withStatus(500);
			$response->getBody()->write('Failed to encode JSON');

			return $response;
		}

		return $response->withBody(Stream::create($jsonData));
	}
}
