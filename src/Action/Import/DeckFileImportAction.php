<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Renderer\JsonRenderer;

/**
 * `POST /collections/{collection}/deck/{csv|json}`: one uploaded file of
 * items into a deck property of one object.
 */
abstract readonly class DeckFileImportAction
{
	public function __construct(protected JsonRenderer $renderer)
	{
	}

	/** The multipart field the file arrives in. */
	abstract protected function fileField(): string;

	abstract protected function import(string $collection, string $objectId, string $property, UploadedFileInterface $file, bool $update): int;

	/**
	 * @param array<string,string> $args
	 *
	 * @throws HttpBadRequestException
	 */
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
		$field = $this->fileField();

		if (!isset($files[$field]) || $files[$field]->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$count = $this->import($args['collection'], $objectId, $property, $files[$field], !empty($params['update']));

		return $this->renderer->json($response, ['import_count' => $count]);
	}
}
