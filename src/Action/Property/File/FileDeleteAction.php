<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class FileDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private RemoverFactory $factory,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$query = $request->getQueryParams();

		$remover  = $this->factory->generateRemoverService($args['collection'], $args['property']);
		$object   = $remover->deleteFile(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['name'],
			$query['path'] ?? null, // Optional path URL parameter
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
