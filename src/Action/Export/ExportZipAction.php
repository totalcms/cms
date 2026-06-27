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
	/** Maximum number of object IDs accepted in a single bulk download request. */
	private const MAX_BULK_IDS = 500;

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
				$ids = array_values(array_filter(array_map('trim', explode(',', $idsParam)), static fn (string $id): bool => $id !== ''));

				if (count($ids) > self::MAX_BULK_IDS) {
					$response = $response->withStatus(400)
						->withHeader('Content-Type', 'application/json');
					$response->getBody()->write((string)json_encode([
						'error'   => 'Too many IDs requested.',
						'limit'   => self::MAX_BULK_IDS,
						'message' => sprintf('Bulk download is limited to %d objects per request. Received %d IDs.', self::MAX_BULK_IDS, count($ids)),
					]));

					return $response;
				}

				$zipPath  = $this->objectZipper->createObjectsZip($collection, $ids);
				$filename = $this->objectZipper->getObjectsZipFilename($collection);
			} else {
				$zipPath  = $this->collectionZipper->createCollectionZip($collection);
				$filename = $this->collectionZipper->getZipFilename($collection);
			}

			if (!file_exists($zipPath)) {
				$response = $response->withStatus(500);
				$response->getBody()->write('Failed to create zip file');

				return $response;
			}

			// Stream the zip file directly from disk rather than reading it fully
			// into memory — prevents memory exhaustion for large collections.
			$fileHandle = fopen($zipPath, 'r');

			if ($fileHandle === false) {
				$response = $response->withStatus(500);
				$response->getBody()->write('Failed to read zip file');

				return $response;
			}

			$fileSize = filesize($zipPath);

			// Remove the temp zip from disk now that the read handle is open: on POSIX
			// the data stays available through the handle for streaming, and the inode
			// is freed when the stream closes — so the temp file isn't leaked.
			unlink($zipPath);

			$response = $response->withHeader('Content-Type', 'application/zip')
				->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

			if ($fileSize !== false) {
				$response = $response->withHeader('Content-Length', (string)$fileSize);
			}

			return $response->withBody(Stream::create($fileHandle));
		} catch (\RuntimeException $e) {
			$response = $response->withStatus(500);
			$response->getBody()->write('Error creating zip: ' . $e->getMessage());

			return $response;
		}
	}
}
