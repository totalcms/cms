<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportJumpStartAction
{
	public function __construct(private JumpStartImporter $jumpStartImporter, private JsonRenderer $renderer)
	{
	}

	/**
	 * Import jumpstart definition from uploaded JSON file.
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = $request->getQueryParams();

		if (isset($params['demo']) && $params['demo'] === 'true') {
			return $this->importDemoDefinition($response);
		}

		// Two callers use this endpoint with different bodies:
		//   1. Admin UI's JumpStart Import form posts multipart/form-data with
		//      a `jumpstart` file field (browser file picker).
		//   2. Server-to-server callers (SyncService push, future CLI flows)
		//      POST a raw JSON body with Content-Type: application/json.
		// Dispatch by Content-Type so both shapes share the same route.
		$contentType = strtolower($request->getHeaderLine('Content-Type'));
		if (str_contains($contentType, 'application/json')) {
			return $this->importFromJsonBody($request, $response);
		}

		return $this->importFromUploadedFile($request, $response);
	}

	private function importFromJsonBody(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = (string)$request->getBody();
		if ($body === '') {
			throw new HttpBadRequestException($request, 'Empty request body');
		}

		try {
			$definition = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new HttpBadRequestException($request, 'Invalid JSON: ' . $e->getMessage());
		}

		if (!is_array($definition)) {
			throw new HttpBadRequestException($request, 'JSON root must be an object');
		}

		$result = $this->jumpStartImporter->importFromDefinition($definition);

		return $this->renderer->json($response, $result->toArray());
	}

	private function importDemoDefinition(ResponseInterface $response): ResponseInterface
	{
		$result = $this->jumpStartImporter->importDemoDefinition();

		return $this->renderer->json($response, $result->toArray());
	}

	private function importFromUploadedFile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['jumpstart']) || $files['jumpstart']->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		// Read the uploaded JSON file
		$jsonContent = (string)$files['jumpstart']->getStream();

		try {
			$definition = json_decode($jsonContent, true);
			if (!is_array($definition)) {
				throw new HttpBadRequestException($request, 'Invalid JSON format');
			}
		} catch (\JsonException $e) {
			throw new HttpBadRequestException($request, 'Invalid JSON: ' . $e->getMessage());
		}

		// Import using JumpstartImporter
		$result = $this->jumpStartImporter->importFromDefinition($definition);

		return $this->renderer->json($response, $result->toArray());
	}
}
