<?php

namespace TotalCMS\Action\Download;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\UploadAccessPolicy;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\FileDownloadedPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\FileStreamRenderer;

/**
 * `/download/upload/{collection}/{id}/{property}/{path}` — a raw upload as
 * an attachment. Served only to the collection's groups when the file
 * property asks for it (`fileProtectedByCollection`).
 */
readonly class DownloadUploadAction
{
	public function __construct(
		private UploadFetcher $uploadFetcher,
		private UploadAccessPolicy $accessPolicy,
		private FileStreamRenderer $streamRenderer,
		private EventDispatcher $events,
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

		$denial = $this->accessPolicy->denialFor($collection, $property, 'fileProtectedByCollection');
		if ($denial !== null) {
			throw new HttpForbiddenException($request, $denial);
		}

		$this->events->dispatch(CoreEvent::FILE_DOWNLOADED, new FileDownloadedPayload(
			$collection,
			$id,
			$property,
			$name,
			(string)$subpath,
			$this->accessPolicy->isProtected($collection, $property, 'fileProtectedByCollection'),
		));

		return $this->streamRenderer->download(
			$response,
			$this->uploadFetcher->mimeType($collection, $id, $property, $name, $subpath),
			$name,
			$this->uploadFetcher->streamFile($collection, $id, $property, $name, $subpath),
		);
	}
}
