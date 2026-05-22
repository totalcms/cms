<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Welcome step submission. Captures the operator's chosen site name and
 * stashes it in the session — `DataPathInstaller` reads `setup_site_name`
 * back out and writes it to settings.json once the data path exists.
 *
 * Locale selection happens via the GET handler's `?lang=` query param;
 * the form's siteName input is the only thing that needs server-side
 * processing here.
 */
readonly class WelcomeSubmitAction
{
	public function __construct(
		private SessionInterface $session,
		private RedirectRenderer $redirectRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body     = (array)$request->getParsedBody();
		$siteName = trim((string)($body['siteName'] ?? ''));

		// Empty string is fine — operator can skip and configure later in
		// the General settings page. We store it either way so the welcome
		// page can prefill the input on back-navigation.
		$this->session->set('setup_site_name', $siteName);

		return $this->redirectRenderer->redirectFor($response, 'setup-environment');
	}
}
