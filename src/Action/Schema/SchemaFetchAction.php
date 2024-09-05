<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\SchemaMetaTransformer;

final class SchemaFetchAction
{
	private JsonRenderer $renderer;
	private SchemaFetcher $schemaFetcher;

	public function __construct(JsonRenderer $renderer, SchemaFetcher $service)
	{
		$this->renderer      = $renderer;
		$this->schemaFetcher = $service;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$schema = $this->schemaFetcher->fetchSchema($args['id']);

		return $this->renderer->jsonItem($response, $schema, new SchemaMetaTransformer());
	}
}
