<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DepotFolderRenamer;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class FolderRenameAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DepotFolderRenamer $renamer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body  = (array)$request->getParsedBody();
		$query = $request->getQueryParams();

		$object = $this->renamer->renameFolder(
			$args['collection'],
			$args['id'],
			$args['property'],
			$query['path'] ?? '',
			$body['name'] ?? '',
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
