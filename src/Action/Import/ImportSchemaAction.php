<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Renderer\JsonRenderer;

final class ImportSchemaAction
{
	public function __construct(
		private SchemaSaver $schemaSaver,
		private JsonRenderer $renderer
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response
	): ResponseInterface
	{
		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['schema']) || $files['schema']->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$schema = $this->schemaSaver->saveSchema(
			json_decode($files['schema']->getStream()->getContents(), true)
		);

		return $this->renderer->json($response, $schema->toArray());
	}
}
