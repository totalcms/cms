<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Renderer\JsonRenderer;

final readonly class ImportCsvAction
{
	public function __construct(private CsvImporter $csvImporter, private JsonRenderer $renderer)
    {
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
		// CSV import can take a long time. Attempt to prevent timeouts.
		set_time_limit(0);

		$collection = $request->getAttribute('collection');
		$params     = (array)$request->getParsedBody();

		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['csv']) || $files['csv']->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$updateObject = isset($params['update']) && !empty($params['update']);
		$queueJobs    = isset($params['queue']) && !empty($params['queue']);

		if ($queueJobs) {
			$this->csvImporter->queueJobs();
		}

		$importCount = $this->csvImporter->import($collection, $files['csv'], $updateObject);

		return $this->renderer->json(
			$response,
			[
				'import_count' => $importCount,
			]
		);
	}
}
