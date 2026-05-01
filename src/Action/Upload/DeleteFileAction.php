<?php

namespace TotalCMS\Action\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadRemover;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;

readonly class DeleteFileAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private UploadRemover $uploadRemover,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];
		[$name, $subpath] = PathUtils::splitPath($args['path'] ?? $args['name'] ?? '');

		$status = $this->uploadRemover->deleteFile($collection, $id, $property, $name, $subpath);

		return $this->renderer->json($response, ['status' => !$status]);
	}
}
