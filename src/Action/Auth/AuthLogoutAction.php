<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\LogoutService;

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
		$redirect    = $queryParams['redirect'] ?? ($referer !== '' ? $referer : '/');

		return $response->withStatus(302)->withHeader('Location', $redirect);
	}
}
