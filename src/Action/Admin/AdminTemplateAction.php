<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminTemplateAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TemplateFetcher $templateFetcher,
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

		// Validate template exists (skip for index and new)
		if ($path !== '' && $path !== 'new') {
			if (!$this->templateFetcher->templateExists($path)) {
				return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
					'url' => [ 'path' => $request->getUri()->getPath(), 'page' => '404', ],
				]);
			}
		}

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

			// Remove the ID - user will set their own ID for the duplicate
			unset($postData['id']);
			$templateData['duplicateData'] = $postData;

			// Force the path to be 'new' for duplication
			$templateData['url']['template'] = 'new';
		}

		return $this->twigRenderer->template($response, 'admin/templates.twig', $templateData);
	}
}
