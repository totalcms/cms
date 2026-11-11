<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AuthMailer;
use TotalCMS\Domain\Auth\Service\AuthThrottle;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;
use TotalCMS\Support\SameOriginRedirect;

/**
 * Process forgot password request and send reset email.
 */
readonly class ForgotPasswordSubmitAction
{
	public function __construct(
		private PasswordResetService $passwordResetService,
		private AuthMailer $mailer,
		private Config $config,
		private PhpSession $session,
		private TranslationService $translator,
		private AuthThrottle $throttle,
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
		$data  = (array)$request->getParsedBody();
		$flash = $this->session->getFlash();

		// Get collection from URL or use default
		$collection = $args['collection'] ?? $this->config->auth['collection'] ?? 'auth';

		// Build redirect URL back to forgot password form
		$router = RouteContext::fromRequest($request)->getRouteParser();
		$url    = $router->urlFor('forgot-password');
		if (isset($args['collection'])) {
			$url = $router->urlFor('forgot-password', ['collection' => $args['collection']]);
		}

		// Where the visitor started: kept on the way back to this form and put
		// on the reset link, so every step can tell (see LoginForm).
		$redirect = SameOriginRedirect::resolve(
			is_string($data['redirect'] ?? null) ? $data['redirect'] : '',
			$request->getUri()->getHost(),
			'',
		);
		if ($redirect !== '') {
			$url .= '?' . http_build_query(['redirect' => $redirect]);
		}

		// Validate email
		$email = trim((string)($data['email'] ?? ''));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$flash->add('error', $this->translator->trans('flash.invalid_email'));

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Per-IP cap so the form cannot flood someone's inbox. Over the cap
		// nothing is sent, and the reply stays the same as a real send.
		if (!$this->throttle->allowMail('forgot', $request)) {
			$flash->add('success', $this->translator->trans('flash.forgot_password_sent'));

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Create reset token
		$result = $this->passwordResetService->createResetToken($email, $collection);

		if (!$result->success || !isset($result->data['token'])) {
			// Still show success message to prevent user enumeration
			$flash->add('success', $this->translator->trans('flash.forgot_password_sent'));

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		$this->mailer->sendPasswordReset($email, (string)$result->data['token'], $collection, $redirect);

		// Always show success message to prevent user enumeration
		// Even if email fails, we don't want to reveal that to the user
		$flash->add('success', $this->translator->trans('flash.forgot_password_sent'));

		return $response->withStatus(302)->withHeader('Location', $url);
	}
}
