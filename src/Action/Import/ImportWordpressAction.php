<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\WordpressImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportWordpressAction
{
	public function __construct(
		private WordpressImporter $importer,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['wordpress']) || $files['wordpress']->getError() !== UPLOAD_ERR_OK) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Missing or invalid file upload. Use field name "wordpress".',
			], 400);
		}

		$xmlContent = (string)$files['wordpress']->getStream();

		if (trim($xmlContent) === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Uploaded file is empty.',
			], 400);
		}

		$params     = (array)$request->getParsedBody();
		$collection = isset($params['collection']) ? trim((string)$params['collection']) : '';

		if ($collection === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Missing required field: collection',
			], 400);
		}

		$options = [];

		if (isset($params['draft'])) {
			$options['draft'] = filter_var($params['draft'], FILTER_VALIDATE_BOOLEAN);
		}

		try {
			$importCount = $this->importer->import($xmlContent, $collection, $options);

			return $this->renderer->json($response, [
				'success'      => true,
				'message'      => sprintf('Successfully queued %d posts for import from WordPress export.', $importCount),
				'import_count' => $importCount,
			]);
		} catch (\Exception $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
			], 500);
		}
	}
}
