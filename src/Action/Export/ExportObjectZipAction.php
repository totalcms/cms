<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Export\Service\ObjectZipper;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

readonly class ExportObjectZipAction
{
	public function __construct(
		private ObjectZipper $objectZipper,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/** @param array<string,string> $args The arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$id         = $args['id'];

		// Verify object exists
		if (!$this->objectFetcher->existsObject($collection, $id)) {
			$response = $response->withStatus(404);
			$response->getBody()->write('Object not found');

			return $response;
		}

		try {
			$zipPath  = $this->objectZipper->createObjectZip($collection, $id);
			$filename = $this->objectZipper->getZipFilename($collection, $id);

			if (!file_exists($zipPath)) {
				$response = $response->withStatus(500);
				$response->getBody()->write('Failed to create zip file');

				return $response;
			}

			// Stream the zip file directly from disk rather than reading it fully
			// into memory — prevents memory exhaustion for large objects.
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
