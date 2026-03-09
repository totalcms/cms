<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportRssAnalyzeAction
{
	public function __construct(
		private RssImporter $importer,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = (array)$request->getParsedBody();

		$url = isset($params['url']) ? trim((string)$params['url']) : '';

		if ($url === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Missing required field: url',
			], 400);
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return $this->renderer->json($response, [
				'success' => false,
				'message' => 'Invalid URL provided',
			], 400);
		}

		try {
			$analysisResult = $this->importer->analyze($url);

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
