<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

readonly class SchemaSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SchemaSaver $service,
	) {
	}

	/**
	 * Invokable Action.
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		return $this->renderer->jsonItem($response, $this->service->saveSchema($data), new SchemaMetaTransformer());
	}
}
