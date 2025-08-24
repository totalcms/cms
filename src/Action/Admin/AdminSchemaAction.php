<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final readonly class AdminSchemaAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
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

		$templateData = [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'schemas',
				'schema' => $args['schema'] ?? '',
				'id'     => $args['id'] ?? '',
			],
		];

		// Handle POST request for schema duplication
		if ($request->getMethod() === 'POST') {
			$postData = (array)$request->getParsedBody();
			// Decode JSON strings back to arrays for fields that should be arrays
			$arrayFields = ['properties', 'required', 'index'];
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
