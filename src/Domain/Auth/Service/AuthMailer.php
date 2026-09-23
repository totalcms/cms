<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

/**
 * The two transactional emails the auth flows send: the password reset link
 * and the email verification link. Each goes through the operator's custom
 * mailer when one is configured (`auth.forgotPasswordMailerId` /
 * `auth.verificationMailerId`), else the built-in template. Registration,
 * resend-verification and forgot-password all send through here, so the
 * link, the expiry and the mailer-or-template choice are made once.
 */
readonly class AuthMailer
{
	public function __construct(
		private EmailService $emailService,
		private EmailSender $emailSender,
		private TwigEngine $twigEngine,
		private UserValidationService $userValidator,
		private Config $config,
	) {
	}

	public function sendPasswordReset(string $email, string $token, string $collection): OperationResult
	{
		$user     = $this->userValidator->findUserByEmail($email, $collection);
		$userName = $user?->toArray()['name'] ?? '';

		$resetUrl      = $this->adminUrl('/admin/reset-password/' . $token);
		$expiryMinutes = (int)($this->config->auth['resetTokenExpiry'] ?? 30);
		$mailerId      = (string)($this->config->auth['forgotPasswordMailerId'] ?? '');

		if ($mailerId !== '') {
			return $this->emailService->sendEmail($mailerId, [
				'email'         => $email,
				'name'          => $userName,
				'user'          => $user?->toArray() ?? [],
				'resetUrl'      => $resetUrl,
				'expiryMinutes' => $expiryMinutes,
				'collection'    => $collection,
			]);
		}

		return $this->sendTemplate('email/password-reset.twig', 'Password Reset Request', $email, $userName, [
			'resetUrl'      => $resetUrl,
			'expiryMinutes' => $expiryMinutes,
		], 'Failed to send password reset email');
	}

	public function sendVerification(string $email, string $token, string $collection, string $name = ''): OperationResult
	{
		$verifyUrl     = $this->adminUrl('/admin/verify-email/' . $token);
		$expiryMinutes = (int)($this->config->auth['verificationTokenExpiry'] ?? 1440);
		$mailerId      = (string)($this->config->auth['verificationMailerId'] ?? '');

		if ($mailerId !== '') {
			return $this->emailService->sendEmail($mailerId, [
				'email'         => $email,
				'name'          => $name,
				'verifyUrl'     => $verifyUrl,
				'expiryMinutes' => $expiryMinutes,
				'collection'    => $collection,
			]);
		}

		return $this->sendTemplate('email/verify-email.twig', 'Verify Your Email', $email, $name, [
			'verifyUrl'     => $verifyUrl,
			'expiryMinutes' => $expiryMinutes,
		], 'Failed to send verification email');
	}

	/**
	 * Render a built-in email template and send it. A render or transport
	 * failure is a failed result, never an exception: the auth flows answer
	 * the same way whether or not the mail went out, to give nothing away.
	 *
	 * @param array<string,mixed> $vars
	 */
	private function sendTemplate(string $template, string $subject, string $email, string $name, array $vars, string $failurePrefix): OperationResult
	{
		try {
			$htmlBody = $this->twigEngine->render($template, ['name' => $name] + $vars);

			return $this->emailSender->send([
				'to'       => $email,
				'toName'   => $name,
				'subject'  => $subject,
				'bodyHtml' => $htmlBody,
			]);
		} catch (\Exception $e) {
			return OperationResult::failure($failurePrefix . ': ' . $e->getMessage());
		}
	}

	/** Absolute, including the configured site URL and the API prefix the admin lives under. */
	private function adminUrl(string $path): string
	{
		return $this->config->url . $this->config->api . $path;
	}
}
