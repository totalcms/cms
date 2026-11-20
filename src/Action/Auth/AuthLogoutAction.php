<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\LogoutService;
use TotalCMS\Support\SameOriginRedirect;

readonly class AuthLogoutAction
{
	/**
	 * The Protect extension's unlock cookies: `tcms_protect_<scope>`, Path=/
	 * (ProtectMiddleware::COOKIE_PREFIX, which a test keeps in step).
	 *
	 * Logging out locks those pages again, so `cms.auth.logout()` signs a visitor
	 * out of a passcode page too. It is done here, not by a listener in Protect:
	 * an extension that starts listening for events gains a capability, and an
	 * updated extension's new capabilities stay off until an admin turns them on,
	 * so logout would have kept failing on every site already using Protect.
	 */
	public const PROTECT_COOKIE_PREFIX = 'tcms_protect_';

	public function __construct(
		private LogoutService $logoutService,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$this->logoutService->logout();

		$referer = $request->getHeaderLine('Referer');

		if (str_contains($referer, 'admin')) {
			$referer = '';
		}

		$queryParams = $request->getQueryParams();
		$candidate   = is_string($queryParams['redirect'] ?? null) ? $queryParams['redirect'] : $referer;

		// Same-origin only: `?redirect=` is in a link anyone can craft, and
		// the Referer names whatever page sent the visitor here — a logout
		// link on another site bounced them back to it (security advisory
		// GHSA-3wpr-fj3p-fgr2, finding 5).
		$redirect = SameOriginRedirect::resolve($candidate, $request->getUri()->getHost(), '/');

		return $this->expireProtectCookies($request, $response)
			->withStatus(302)
			->withHeader('Location', $redirect);
	}

	/** Expire every Protect unlock cookie the browser sent. */
	private function expireProtectCookies(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$secure = $request->getUri()->getScheme() === 'https' ? '; Secure' : '';

		foreach (array_keys($request->getCookieParams()) as $name) {
			// Protect reduces a scope to these characters, so anything else is not its cookie.
			if (!is_string($name) || preg_match('/^' . self::PROTECT_COOKIE_PREFIX . '[A-Za-z0-9_-]+$/', $name) !== 1) {
				continue;
			}

			$response = $response->withAddedHeader(
				'Set-Cookie',
				$name . '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; SameSite=Lax; HttpOnly' . $secure,
			);
		}

		return $response;
	}
}
