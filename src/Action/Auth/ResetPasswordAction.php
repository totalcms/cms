<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Display the reset password form (validates token first).
 */
readonly class ResetPasswordAction
{
	public function __construct(
		private PasswordResetService $passwordResetService,
		private TwigRenderer $twigRenderer,
		private PhpSession $session,
	) {
	}

	/**
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$token = $args['token'] ?? '';

		if ($token === '') {
			$this->session->getFlash()->add('error', 'Invalid reset link.');

			// Redirect to forgot password page
			$router = RouteContext::fromRequest($request)->getRouteParser();
			$url    = $router->urlFor('forgot-password');

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Validate token
		$validation = $this->passwordResetService->validateToken($token);

		if (!$validation->success) {
			$this->session->getFlash()->add('error', $validation->message);

			// Redirect to forgot password page
			$router = RouteContext::fromRequest($request)->getRouteParser();
			$url    = $router->urlFor('forgot-password');

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Token is valid, show reset form
		$queryParams = $request->getQueryParams();

		return $this->twigRenderer->template($response, 'admin/reset-password.twig', [
			'token'      => $token,
			'email'      => $validation->data['email'] ?? '',
			'collection' => $validation->data['collection'] ?? '',
			'redirect'   => $queryParams['redirect'] ?? '',
		]);
	}
}
