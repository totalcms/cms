<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

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
		private EmailService $emailService,
		private EmailSender $emailSender,
		private TwigEngine $twigEngine,
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
			$this->sendVerificationEmail($email, $collection, (string)$result->data['token']);
		}

		// Always show the same success message regardless of outcome.
		$flash->add('success', $this->translator->trans('flash.resend_verification_sent'));

		return $response->withStatus(302)->withHeader('Location', $url);
	}

	private function sendVerificationEmail(string $email, string $collection, string $token): void
	{
		$verifyUrl     = $this->config->url . $this->config->api . '/admin/verify-email/' . $token;
		$expiryMinutes = (int)($this->config->auth['verificationTokenExpiry'] ?? 1440);

		$mailerId = (string)($this->config->auth['verificationMailerId'] ?? '');

		if ($mailerId !== '') {
			$this->emailService->sendEmail($mailerId, [
				'email'         => $email,
				'name'          => '',
				'verifyUrl'     => $verifyUrl,
				'expiryMinutes' => $expiryMinutes,
				'collection'    => $collection,
			]);

			return;
		}

		$this->sendDefaultVerificationEmail($email, $verifyUrl, $expiryMinutes);
	}

	private function sendDefaultVerificationEmail(string $email, string $verifyUrl, int $expiryMinutes): OperationResult
	{
		try {
			$htmlBody = $this->twigEngine->render('email/verify-email.twig', [
				'name'          => '',
				'verifyUrl'     => $verifyUrl,
				'expiryMinutes' => $expiryMinutes,
			]);

			return $this->emailSender->send([
				'to'       => $email,
				'toName'   => '',
				'subject'  => 'Verify Your Email',
				'bodyHtml' => $htmlBody,
			]);
		} catch (\Exception $e) {
			return OperationResult::failure('Failed to send verification email: ' . $e->getMessage());
		}
	}
}
