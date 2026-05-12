<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action for displaying settings management interface.
 */
readonly class AdminSettingsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// Get section from URL, default to general
		$section = $args['section'] ?? 'general';

		return $this->twigRenderer->template($response, 'admin/settings.twig', [
			'url' => [
				'path'    => $request->getUri()->getPath(),
				'query'   => $request->getUri()->getQuery(),
				'params'  => $args,
				'page'    => 'settings',
				'section' => $section,
			],
			'currentSection' => $section,
		]);
	}
}
