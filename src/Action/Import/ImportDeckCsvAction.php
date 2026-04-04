<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Import\DeckCsvImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportDeckCsvAction
{
	public function __construct(
		private DeckCsvImporter $deckCsvImporter,
		private JsonRenderer $renderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		set_time_limit(0);

		$params   = (array)$request->getParsedBody();
		$objectId = trim((string)($params['object'] ?? ''));
		$property = trim((string)($params['property'] ?? ''));

		if ($objectId === '' || $property === '') {
			throw new HttpBadRequestException($request, 'Object and property are required');
		}

		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['csv']) || $files['csv']->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$update = isset($params['update']) && !empty($params['update']);

		$importCount = $this->deckCsvImporter->import(
			$args['collection'],
			$objectId,
			$property,
			$files['csv'],
			$update,
		);

		return $this->renderer->json($response, ['import_count' => $importCount]);
	}
}
