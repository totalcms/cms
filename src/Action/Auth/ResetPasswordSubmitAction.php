<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Translation\TranslationService;
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
		private TranslationService $translator,
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
			$flash->add('error', $this->translator->trans('flash.invalid_token'));

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		if ($newPassword === '') {
			$flash->add('error', $this->translator->trans('flash.password_required'));

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		if ($newPassword !== $confirm) {
			$flash->add('error', $this->translator->trans('flash.passwords_no_match'));

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		// Check minimum password length (should match auth schema requirements)
		if (strlen($newPassword) < 4) {
			$flash->add('error', $this->translator->trans('flash.password_too_short'));

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		// Validate token first to get collection info (before it gets deleted)
		$validation = $this->passwordResetService->validateToken($token);
		if (!$validation->success) {
			$flash->add('error', $validation->message);

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}
		$collection = $validation->data['collection'] ?? $this->config->auth['collection'] ?? 'auth';

		// Reset password (this will delete the token)
		$result = $this->passwordResetService->resetPassword($token, $newPassword);

		if (!$result->success) {
			$flash->add('error', $result->message);

			return $response->withStatus(302)->withHeader('Location', $resetUrl);
		}

		// Check for custom redirect URL
		$redirect = trim((string)($data['redirect'] ?? ''));

		if ($redirect !== '') {
			return $response->withStatus(302)->withHeader('Location', $redirect);
		}

		// Build login URL
		$loginUrl = $router->urlFor('login');
		if ($collection !== ($this->config->auth['collection'] ?? 'auth')) {
			$loginUrl = $router->urlFor('login', ['collection' => $collection]);
		}

		// Set success flash message for login page
		$flash->add('success', $this->translator->trans('flash.password_reset_success'));

		return $response->withStatus(302)->withHeader('Location', $loginUrl);
	}
}
