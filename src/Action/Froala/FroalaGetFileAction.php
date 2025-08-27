<?php

namespace TotalCMS\Action\Froala;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Property\Service\UploadFetcher;

readonly class FroalaGetFileAction
{
	public function __construct(
		private UploadFetcher $uploadFetcher,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];
		$name       = $args['name'];

		if (!$this->uploadFetcher->fileExists($collection, $id, $property, $name)) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		$mimeType = $this->uploadFetcher->mimeType($collection, $id, $property, $name);
		$response = $response->withHeader('Content-Type', $mimeType);

		$stream = $this->uploadFetcher->streamFile($collection, $id, $property, $name);

		return $response->withBody(Stream::create($stream));
	}
}
