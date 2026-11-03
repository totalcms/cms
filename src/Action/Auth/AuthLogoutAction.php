<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\LogoutService;
use TotalCMS\Support\SameOriginRedirect;

readonly class AuthLogoutAction
{
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

		return $response->withStatus(302)->withHeader('Location', $redirect);
	}
}
