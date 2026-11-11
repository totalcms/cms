<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * Applies the page's language to an API request.
 *
 * The browser form runtime sends `X-Locale` with the page's `<html lang>`,
 * so a Spanish page on a bilingual site gets Total CMS's own messages (demo
 * mode's refusal, validation errors) in Spanish even for a visitor who is not
 * signed in. The header is untrusted input: it must look like a locale, and
 * the locale must be one Total CMS ships text for. Anything else is ignored.
 */
final readonly class RequestLocaleMiddleware implements MiddlewareInterface
{
	/** Also read by DefaultErrorHandler, which runs after this middleware has put the locale back. */
	public const HEADER = 'X-Locale';

	public function __construct(private TranslationService $translator)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$locale = $this->requestedLocale($request);
		if ($locale === null) {
			return $handler->handle($request);
		}

		// The translator is shared, and a long-lived worker (FrankenPHP) serves
		// the next request with it, so put the site's locale back afterwards.
		$previous = $this->translator->getLocale();
		$this->translator->setLocale($locale);

		try {
			return $handler->handle($request);
		} finally {
			$this->translator->setLocale($previous);
		}
	}

	/** The locale the request asks for, or null when it is absent, malformed or not one we ship. */
	private function requestedLocale(ServerRequestInterface $request): ?string
	{
		return $this->translator->resolveLocale($request->getHeaderLine(self::HEADER));
	}
}
