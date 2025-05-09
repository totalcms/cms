<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportJsonAction
{
	private JsonImporter $jsonImporter;
	private JsonRenderer $renderer;

	public function __construct(JsonImporter $jsonImporter, JsonRenderer $renderer)
	{
		$this->jsonImporter = $jsonImporter;
		$this->renderer    = $renderer;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 *
	 * @throws HttpBadRequestException
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$collection = $request->getAttribute('collection');
		$params     = (array)$request->getParsedBody();

		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['json']) || $files['json']->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$updateObject = isset($params['update']) && !empty($params['update']);
		$queueJobs    = isset($params['queue'])  && !empty($params['queue']);

		if ($queueJobs) {
			$this->jsonImporter->queueJobs();
		}

		$importCount = $this->jsonImporter->import($collection, $files['json'], $updateObject);

		return $this->renderer->json(
			$response,
			[
				'import_count' => $importCount,
			]
		);
	}
}
