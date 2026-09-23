<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AuthMailer;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * Process a resend-verification request.
 *
 * Issues a new email verification link for an existing inactive account.
 * Always responds with a generic success message — never reveals whether the
 * email exists or is already active (same anti-enumeration posture as
 * forgot-password).
 */
readonly class ResendVerificationSubmitAction
{
	public function __construct(
		private EmailVerificationService $verificationService,
		private AuthMailer $mailer,
		private Config $config,
		private PhpSession $session,
		private TranslationService $translator,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data  = (array)$request->getParsedBody();
		$flash = $this->session->getFlash();

		$collection = $args['collection'] ?? $this->config->auth['collection'] ?? 'auth';

		$router = RouteContext::fromRequest($request)->getRouteParser();
		$url    = isset($args['collection'])
			? $router->urlFor('resend-verification', ['collection' => $args['collection']])
			: $router->urlFor('resend-verification');

		$email = trim((string)($data['email'] ?? ''));

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$flash->add('error', $this->translator->trans('flash.invalid_email'));

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		$result = $this->verificationService->resendVerificationToken($email, $collection);

		// Only actually send the email when the service issued a token. The
		// service swallows the "already active" / "user not found" cases and
		// returns a generic success WITHOUT a token, so this branch naturally
		// skips email sending in those cases.
		if ($result->success && isset($result->data['token'])) {
			$this->mailer->sendVerification($email, (string)$result->data['token'], $collection);
		}

		// Always show the same success message regardless of outcome.
		$flash->add('success', $this->translator->trans('flash.resend_verification_sent'));

		return $response->withStatus(302)->withHeader('Location', $url);
	}
}
