<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

final class SchemaUpdateAction
{

	public function __construct(
		private JsonRenderer $renderer,
		private SchemaSaver $service
	) {
	}

	/**
	 * Invokable Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = json_decode($request->getBody(), true);
		$schema = $this->service->updateSchema($args['id'], $data);

		return $this->renderer->jsonItem($response, $schema, new SchemaMetaTransformer());
	}
}
