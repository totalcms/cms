<?php

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
				$ids      = array_values(array_filter(array_map('trim', explode(',', $idsParam)), static fn (string $id): bool => $id !== ''));
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
