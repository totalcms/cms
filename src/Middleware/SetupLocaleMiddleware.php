<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * Applies the user's locale selection during the setup wizard.
 * Reads from the session key set on the environment check page.
 */
readonly class SetupLocaleMiddleware implements MiddlewareInterface
{
	public function __construct(
		private SessionInterface $session,
		private TranslationService $translationService,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		$locale = $this->session->get('setup_locale');

		if (is_string($locale) && $locale !== '' && $locale !== 'en_US') {
			$this->translationService->setLocale($locale);
		}

		return $handler->handle($request);
	}
}
