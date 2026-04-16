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

		// Handle custom import from uploaded file
		return $this->importFromUploadedFile($request, $response);
	}

	private function importDemoDefinition(ResponseInterface $response): ResponseInterface
	{
		$definition = $this->jumpStartImporter->importDemoDefinition();

		return $this->renderer->json($response, $definition);
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

		return $this->renderer->json($response, $result);
	}
}
