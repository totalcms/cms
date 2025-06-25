<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Export\Service\CollectionZipper;

final class ExportZipAction
{
	public function __construct(
		private CollectionZipper $collectionZipper,
	) {
	}

	/** @param array<string,string> $args The arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		
		try {
			$zipPath = $this->collectionZipper->createCollectionZip($collection);
			$filename = $this->collectionZipper->getZipFilename($collection);
			
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
				->withHeader('Content-Length', (string) strlen($zipContent));
			
			return $response->withBody(Stream::create($zipContent));
			
		} catch (\RuntimeException $e) {
			$response = $response->withStatus(500);
			$response->getBody()->write('Error creating zip: ' . $e->getMessage());
			return $response;
		}
	}
}