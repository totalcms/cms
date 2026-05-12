<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\AlloyImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportAlloyAnalyzeAction
{
	public function __construct(
		private AlloyImporter $importer,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = (array)$request->getParsedBody();

		// Validate required parameters
		$requiredFields = ['blog', 'image_uploads', 'embeds', 'droplets'];
		foreach ($requiredFields as $field) {
			if (empty($params[$field])) {
				return $this->renderer->json($response, [
					'success' => false,
					'message' => sprintf('Missing required field: %s', $field),
				], 400);
			}
		}

		$folders = [
			'blog'          => $params['blog'],
			'image_uploads' => $params['image_uploads'],
			'embeds'        => $params['embeds'],
			'droplets'      => $params['droplets'],
		];

		try {
			$analysisResult = $this->importer->analyze($folders);

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
