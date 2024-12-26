<?php

namespace TotalCMS\Action\Froala;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadRemover;
use TotalCMS\Renderer\JsonRenderer;

final class FroalaDeleteFileAction
{
	public function __construct(
		private JsonRenderer  $renderer,
		private UploadRemover $uploadRemover,
	){}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface
	{
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];
		$name       = $args['name'];

		$status = $this->uploadRemover->deleteFile($collection, $id, $property, $name);

		return $this->renderer->json($response, [ "deleted" => $status ]);
	}
}
