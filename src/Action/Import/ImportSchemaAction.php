<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Renderer\JsonRenderer;

final readonly class ImportSchemaAction
{
	public function __construct(
		private SchemaSaver $schemaSaver,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['schema']) || $files['schema']->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$jsonContent = $files['schema']->getStream()->getContents();
		$schemaData  = json_decode($jsonContent, true);

		// Handle JSON decode errors
		if ($schemaData === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new HttpBadRequestException($request, 'Invalid JSON: ' . json_last_error_msg());
		}

		try {
			$schema = $this->schemaSaver->saveSchema($schemaData);
		} catch (\InvalidArgumentException $e) {
			throw new HttpBadRequestException($request, 'Invalid schema data: ' . $e->getMessage());
		}

		return $this->renderer->json($response, $schema->toArray());
	}
}
