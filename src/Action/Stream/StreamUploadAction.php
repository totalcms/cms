<?php

namespace TotalCMS\Action\Stream;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\RawUploadGate;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\FileStreamRenderer;

/**
 * `/stream/upload/{collection}/{id}/{property}/{path}` — a raw upload inline
 * with byte ranges (video). A file that belongs to a protected file field,
 * depot or nested file keeps that protection here ({@see RawUploadGate}); a
 * loose upload is limited to the collection's groups when its property asks
 * for it (`videoProtectedByCollection`).
 */
readonly class StreamUploadAction
{
	public function __construct(
		private UploadFetcher $uploadFetcher,
		private RawUploadGate $gate,
		private FileStreamRenderer $streamRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection       = $args['collection'];
		$id               = $args['id'];
		$property         = $args['property'];
		[$name, $subpath] = PathUtils::splitPath($args['path'] ?? $args['name'] ?? '');

		if (!$this->uploadFetcher->fileExists($collection, $id, $property, $name, $subpath)) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		$denial = $this->gate->denialFor($collection, $id, $property, $subpath, 'videoProtectedByCollection');
		if ($denial !== null) {
			throw new HttpForbiddenException($request, $denial);
		}

		return $this->streamRenderer->stream(
			$request,
			$response,
			$this->uploadFetcher->mimeType($collection, $id, $property, $name, $subpath),
			$name,
			$this->uploadFetcher->fileSize($collection, $id, $property, $name, $subpath),
			fn () => $this->uploadFetcher->streamFile($collection, $id, $property, $name, $subpath),
		);
	}
}
