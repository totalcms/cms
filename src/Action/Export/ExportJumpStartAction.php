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
		$name = $queryParams['name'] ?? '';
		$description = $queryParams['description'] ?? '';
		
		// Export current CMS data to jumpstart format
		$jumpStartData = $this->jumpStartExporter->exportCurrentData($name, $description);
		
		// Set response headers for JSON download
		$filename = !empty($name) ? 
			sprintf('jumpstart-%s.json', preg_replace('/[^a-zA-Z0-9-_]/', '-', strtolower($name))) :
			sprintf('jumpstart-export-%s.json', date('Y-m-d-H-i-s'));
			
		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

		$jsonData = $jumpStartData->toJson();

		return $response->withBody(Stream::create($jsonData));
	}
}