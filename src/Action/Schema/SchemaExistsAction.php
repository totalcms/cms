<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final readonly class SchemaExistsAction
{
	public function __construct(private SchemaFetcher $schemaFetcher)
	{
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @throws HttpNotFoundException
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$exists = $this->schemaFetcher->schemaExists($args['id']);

		if ($exists === false) {
			throw new HttpNotFoundException($request, 'Schema not found');
		}

		return $response;
	}
}
