<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Error monitoring step submission. Persists the operator's choice to the
 * same `general` settings section the admin Settings page writes, so the
 * toggle there reflects (and can later change) what was chosen here.
 */
readonly class ErrorMonitoringSetupSubmitAction
{
	public function __construct(
		private SettingsSaver $settingsSaver,
		private SetupStateManager $setupState,
		private RedirectRenderer $redirectRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = (array)$request->getParsedBody();

		// Unchecked checkboxes are absent from the POST body — absence means
		// the operator opted out.
		$this->settingsSaver->saveSection('general', ['sentry' => isset($body['sentry'])]);

		$this->setupState->completeStep('error-monitoring');

		return $this->redirectRenderer->redirectFor($response, 'setup-server-config');
	}
}
