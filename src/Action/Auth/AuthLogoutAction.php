<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Domain\Auth\Service\LogoutService;

final class AuthLogoutAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private LogoutService $logoutService,
	) {}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		try {
			$this->logoutService->logout();
		} catch (\Exception $e) {
			// Do nothing
		}
		return $this->renderer->json($response, [
			'loggedout' => LogoutService::destroySession()
		]);
	}
}
