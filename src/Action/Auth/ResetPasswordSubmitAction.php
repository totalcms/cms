<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Support\Config;

/**
 * Process password reset with new password.
 */
readonly class ResetPasswordSubmitAction
{
	public function __construct(
		private PasswordResetService $passwordResetService,
		private Config $config,
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
		$data   = (array)$request->getParsedBody();
		$flash  = $this->session->getFlash();
		$router = RouteContext::fromRequest($request)->getRouteParser();

		$token       = $args['token'] ?? '';
		$newPassword = trim((string)($data['password'] ?? ''));
		$confirm     = trim((string)($data['password_confirm'] ?? ''));

		// Build URL to redirect back to reset form on errors
		$resetUrl = $router->urlFor('reset-password', ['token' => $token]);

		// Validate inputs
		if ($token === '') {
			$flash->add('error', 'Invalid reset token.');

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		if ($newPassword === '') {
			$flash->add('error', 'Please enter a new password.');

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		if ($newPassword !== $confirm) {
			$flash->add('error', 'Passwords do not match.');

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		// Check minimum password length (should match auth schema requirements)
		if (strlen($newPassword) < 4) {
			$flash->add('error', 'Password must be at least 4 characters long.');

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		// Validate token first to get collection info (before it gets deleted)
		$validation = $this->passwordResetService->validateToken($token);
		if (!$validation['valid']) {
			$flash->add('error', $validation['message']);

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}
		$collection = $validation['collection'] ?? $this->config->auth['collection'] ?? 'auth';

		// Reset password (this will delete the token)
		$result = $this->passwordResetService->resetPassword($token, $newPassword);

		if (!$result['success']) {
			$flash->add('error', $result['message']);

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		// Build login URL
		$loginUrl = $router->urlFor('login');
		if ($collection !== ($this->config->auth['collection'] ?? 'auth')) {
			$loginUrl = $router->urlFor('login', ['collection' => $collection]);
		}

		// Set success flash message for login page
		$flash->add('success', 'Password reset successful! You can now log in with your new password.');

		return $response->withStatus(302)->withHeader('Location', $loginUrl);
	}
}
