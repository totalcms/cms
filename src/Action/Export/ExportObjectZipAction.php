<?php

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

			$zipContent = file_get_contents($zipPath);

			// Clean up temporary file
			unlink($zipPath);

			if ($zipContent === false) {
				$response = $response->withStatus(500);
				$response->getBody()->write('Failed to read zip file');

				return $response;
			}

			$response = $response->withHeader('Content-Type', 'application/zip')
				->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
				->withHeader('Content-Length', (string)strlen($zipContent));

			return $response->withBody(Stream::create($zipContent));
		} catch (\RuntimeException $e) {
			$response = $response->withStatus(500);
			$response->getBody()->write('Error creating zip: ' . $e->getMessage());

			return $response;
		}
	}
}
