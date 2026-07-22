<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Setup wizard step: error monitoring choice.
 *
 * Presents the "Share Application Errors with Developer" toggle so the
 * operator makes an explicit choice during install instead of discovering
 * the default-on setting later in General settings. The checkbox always
 * starts checked — this is a fresh install, so the setting can only be at
 * its default. The submit action persists the choice through the same
 * settings pipeline the admin General settings page uses.
 */
readonly class ErrorMonitoringSetupAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response, 'setup/error-monitoring.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
		]);
	}
}
