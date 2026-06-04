<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * Applies the logged-in user's preferred locale to the admin request.
 *
 * Reads the `locale` field from the authenticated user's record (auth schema).
 * When set, it switches the admin UI translation catalog (via TranslationService,
 * which backs both the server-side `t()` strings and the injected JS catalog)
 * plus PHP intl / CakePHP I18n formatting for the remainder of the request.
 * When blank, nothing changes and the site default (`$config->locale`) stands.
 *
 * Wired into the admin auth stack, so it only fires for authenticated requests
 * and before the action renders.
 */
readonly class UserLocaleMiddleware implements MiddlewareInterface
{
	public function __construct(
		private AccessManager $accessManager,
		private TranslationService $translationService,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		$locale = $this->accessManager->userData()['locale'] ?? null;

		if (is_string($locale) && $locale !== '') {
			$this->translationService->setLocale($locale);

			if (extension_loaded('intl')) {
				\Locale::setDefault($locale);
				\Cake\I18n\I18n::setLocale($locale);
			}
		}

		return $handler->handle($request);
	}
}
