<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DepotFolderSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final readonly class FolderSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DepotFolderSaver $saver,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body  = (array)$request->getParsedBody();

		$object = $this->saver->createFolder(
			$args['collection'],
			$args['id'],
			$args['property'],
			$body['path'],
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
