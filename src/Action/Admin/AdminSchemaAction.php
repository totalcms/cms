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
		// POST /schemas/new - duplicate schema with data

		// Validate schema exists (skip for index and new)
		$schema = $args['schema'] ?? '';
		if ($schema !== '' && $schema !== 'new') {
			if (!$this->schemaFetcher->schemaExists($schema)) {
				return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
					'url' => [ 'path' => $request->getUri()->getPath(), 'page' => '404', ],
				]);
			}
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
			// Decode JSON strings back to arrays for fields that should be arrays
			$arrayFields = ['properties', 'required', 'index', 'inheritFrom'];
			foreach ($arrayFields as $field) {
				if (isset($postData[$field]) && is_string($postData[$field])) {
					$postData[$field] = json_decode($postData[$field], true) ?? [];
				}
			}
			// Set the schema ID to the one being duplicated
			$postData['id'] .= '-duplicate';
			$templateData['duplicateData'] = $postData;
			// Force the schema to be 'new' for duplication
			$templateData['url']['schema'] = 'new';
		}

		return $this->twigRenderer->template($response, 'admin/schema.twig', $templateData);
	}
}
