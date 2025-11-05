<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminSchemaAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// /schemas - index page
		// /schemas/{schema} - schema edit page
		// /schemas/new - schema create form
		// /schemas/-import - schema import form
		// POST /schemas/new - duplicate schema with data

		// Validate schema exists (skip for index, new, and import)
		$schema = $args['schema'] ?? '';
		if ($schema !== '' && $schema !== 'new' && $schema !== '-import' && !$this->schemaFetcher->schemaExists($schema)) {
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
		}

		$templateData = [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'schemas',
				'schema' => $args['schema'] ?? '',
				'action' => $args['action'] ?? '',
			],
		];

		// Handle POST request for schema duplication
		if ($request->getMethod() === 'POST') {
			$postData = (array)$request->getParsedBody();

			// Check if this is a duplication request (contains 'duplicate' with schema ID)
			if (isset($postData['duplicate']) && is_string($postData['duplicate'])) {
				$duplicateId = $postData['duplicate'];

				// Fetch the schema to duplicate
				$schemaToDuplicate = $this->schemaFetcher->fetchRawSchema($duplicateId);

				// Convert to array and append '-duplicate' to ID
				$duplicateData       = $schemaToDuplicate->toArray();
				$duplicateData['id'] = $duplicateId . '-duplicate';

				$templateData['duplicateData'] = $duplicateData;
				// Force the schema to be 'new' for duplication
				$templateData['url']['schema'] = 'new';
			}
		}

		return $this->twigRenderer->template($response, 'admin/schema.twig', $templateData);
	}
}
