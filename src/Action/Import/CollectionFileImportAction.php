<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Renderer\JsonRenderer;

/**
 * `POST /collections/{collection}/{csv|json}`: one uploaded file of records
 * into the collection, with the `update` and `queue` flags.
 */
abstract readonly class CollectionFileImportAction
{
	public function __construct(protected JsonRenderer $renderer)
	{
	}

	/** The multipart field the file arrives in. */
	abstract protected function fileField(): string;

	abstract protected function import(string $collection, UploadedFileInterface $file, bool $update, bool $queue): int;

	/**
	 * @throws HttpBadRequestException
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		// Imports can take a long time. Attempt to prevent timeouts.
		set_time_limit(0);

		$collection = (string)$request->getAttribute('collection');
		$params     = (array)$request->getParsedBody();

		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();
		$field = $this->fileField();

		if (!isset($files[$field]) || $files[$field]->getError() !== UPLOAD_ERR_OK) {
			throw new HttpBadRequestException($request, 'Upload failed');
		}

		$count = $this->import($collection, $files[$field], !empty($params['update']), !empty($params['queue']));

		return $this->renderer->json($response, ['import_count' => $count]);
	}
}
