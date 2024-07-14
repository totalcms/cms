<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaRemover;

final class SchemaDeleteAction
{
	private SchemaRemover $schemaRemover;

	public function __construct(SchemaRemover $service)
	{
		$this->schemaRemover = $service;
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
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$deleted = $this->schemaRemover->deleteSchema($args['id']);

		if ($deleted === false) {
			return $response->withStatus(500);
		}

		return $response;
	}
}
