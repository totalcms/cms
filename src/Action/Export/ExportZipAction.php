<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Export\Service\CollectionZipper;
use TotalCMS\Domain\Export\Service\ObjectZipper;

readonly class ExportZipAction
{
	public function __construct(
		private CollectionZipper $collectionZipper,
		private ObjectZipper $objectZipper,
	) {
	}

	/** @param array<string,string> $args The arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];

		// `?ids=a,b,c` exports just those objects (the table's bulk download);
		// without it, the whole collection is zipped.
		$idsParam = (string)($request->getQueryParams()['ids'] ?? '');

		try {
			if ($idsParam !== '') {
				$ids = array_values(array_filter(array_map(trim(...), explode(',', $idsParam)), static fn (string $id): bool => $id !== ''));

				$zipPath  = $this->objectZipper->createObjectsZip($collection, $ids);
				$filename = $this->objectZipper->getObjectsZipFilename($collection);
			} else {
				$zipPath  = $this->collectionZipper->createCollectionZip($collection);
				$filename = $this->collectionZipper->getZipFilename($collection);
			}

			if (!file_exists($zipPath)) {
				$response = $response->withStatus(500)
					->withHeader('Content-Type', 'application/json');
				$response->getBody()->write((string)json_encode([
					'error'   => 'Export failed.',
					'message' => 'Failed to create zip file',
				]));

				return $response;
			}

			// Stream the zip file directly from disk rather than reading it fully
			// into memory — prevents memory exhaustion for large collections.
			$fileHandle = fopen($zipPath, 'r');

			if ($fileHandle === false) {
				$response = $response->withStatus(500)
					->withHeader('Content-Type', 'application/json');
				$response->getBody()->write((string)json_encode([
					'error'   => 'Export failed.',
					'message' => 'Failed to read zip file',
				]));

				return $response;
			}

			$fileSize = filesize($zipPath);

			// Remove the temp zip from disk now that the read handle is open: on POSIX
			// the data stays available through the handle for streaming, and the inode
			// is freed when the stream closes — so the temp file isn't leaked. This
			// relies on POSIX unlink semantics; named temp files cannot be removed while
			// open on Windows, but T3 targets POSIX servers only.
			unlink($zipPath);

			$response = $response->withHeader('Content-Type', 'application/zip')
				->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

			if ($fileSize !== false) {
				$response = $response->withHeader('Content-Length', (string)$fileSize);
			}

			return $response->withBody(Stream::create($fileHandle));
		} catch (\RuntimeException $e) {
			// "No objects found" is a client error (bad/non-existent IDs) → 400.
			$isClientError = str_starts_with($e->getMessage(), 'No objects found');
			$status        = $isClientError ? 400 : 500;

			$response = $response->withStatus($status)
				->withHeader('Content-Type', 'application/json');
			$response->getBody()->write((string)json_encode([
				'error'   => $isClientError ? 'No objects found.' : 'Export failed.',
				'message' => $e->getMessage(),
			]));

			return $response;
		}
	}
}
