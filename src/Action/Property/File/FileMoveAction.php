<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class FileMoveAction
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
		$object = $remover->deleteFile(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['file'],
			$query['path'] ?? null, // Optional path URL parameter
		);

		if (!$object instanceof ObjectData) {
			throw new \RuntimeException('Unable to collect object data from deleted file');
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
