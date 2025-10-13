<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminTemplateAction
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
		// /templates - index page
		// /templates/{path} - template edit page (path can be "folder/template" or just "template")
		// /templates/new - template create form
		// POST /templates/new - duplicate template with data

		$path = $args['path'] ?? '';

		$templateData = [
			'url' => [
				'path'     => $request->getUri()->getPath(),
				'query'    => $request->getUri()->getQuery(),
				'params'   => $args,
				'page'     => 'templates',
				'template' => $path,
			],
		];

		// Handle POST request for template duplication
		if ($request->getMethod() === 'POST') {
			$postData = (array)$request->getParsedBody();

			// Set the template ID to the one being duplicated
			$postData['id'] .= '-duplicate';
			$templateData['duplicateData'] = $postData;

			// Force the path to be 'new' for duplication
			$templateData['url']['template'] = 'new';
		}

		return $this->twigRenderer->template($response, 'admin/template.twig', $templateData);
	}
}
