<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class ExportSchemaAction
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$schema = $this->schemaFetcher->fetchSchema($args['schema']);

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="schema-%s.json"', $schema->id));

		return $response->withBody(Stream::create($schema->toJson()));
	}
}
