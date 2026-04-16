<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\WordpressImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportWordpressAnalyzeAction
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

		try {
			$analysisResult = $this->importer->analyze($xmlContent);

			return $this->renderer->json($response, [
				'success' => true,
				'message' => 'Analysis completed successfully',
				'data'    => $analysisResult,
			]);
		} catch (\Exception $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Analysis failed: ' . $e->getMessage(),
			], 500);
		}
	}
}
