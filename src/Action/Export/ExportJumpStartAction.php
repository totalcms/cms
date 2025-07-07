<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;

final class ExportJumpStartAction
{
	public function __construct(
		private JumpStartExporter $jumpStartExporter,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$queryParams = $request->getQueryParams();
		$name        = $queryParams['name'] ?? '';
		$description = $queryParams['description'] ?? '';

		$this->jumpStartExporter->setMetadata($name, $description);

		// Export current CMS data to jumpstart format
		$jumpStartData = $this->jumpStartExporter->exportCurrentData();

		$date = date('Ymd-His');

		// Set response headers for JSON download
		$filename = !empty($name) ?
			sprintf('jumpstart-%s-%s.json', preg_replace('/[^a-zA-Z0-9-_]/', '-', strtolower($name)), $date) :
			sprintf('jumpstart-export-%s.json', $date);

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

		$jsonData = $jumpStartData->toJson();

		return $response->withBody(Stream::create($jsonData));
	}
}
