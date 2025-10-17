<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/**
 * Process forgot password request and send reset email.
 */
readonly class ForgotPasswordSubmitAction
{
	public function __construct(
		private PasswordResetService $passwordResetService,
		private EmailService $emailService,
		private EmailSender $emailSender,
		private IndexSearcher $indexSearcher,
		private ObjectFetcher $objectFetcher,
		private TwigEngine $twigEngine,
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

		// Validate email
		$email = trim((string)($data['email'] ?? ''));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$flash->add('error', 'Please enter a valid email address.');

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Create reset token
		$result = $this->passwordResetService->createResetToken($email, $collection);

		if (!$result['success'] || !isset($result['token'])) {
			// Still show success message to prevent user enumeration
			$flash->add('success', 'If an account exists with that email, you will receive a password reset link.');

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		$token = $result['token'];

		// Send reset email
		$emailResult = $this->sendResetEmail($email, $token, $collection);

		// Always show success message to prevent user enumeration
		// Even if email fails, we don't want to reveal that to the user
		$flash->add('success', 'If an account exists with that email, you will receive a password reset link.');

		return $response->withStatus(302)->withHeader('Location', $url);
	}

	/**
	 * Send password reset email to user.
	 *
	 * @return array{success:bool,message:string}
	 */
	private function sendResetEmail(string $email, string $token, string $collection): array
	{
		// Fetch user to get their name
		$user     = $this->findUserByEmail($email, $collection);
		$userName = $user?->toArray()['name'] ?? '';

		// Build reset URL
		$resetUrl = $this->config->url . $this->config->api . '/reset-password/' . $token;

		// Get expiry minutes from config
		$expiryMinutes = $this->config->auth['resetTokenExpiry'] ?? 30;

		// Check if custom mailer is configured
		$mailerId = $this->config->auth['forgotPasswordMailerId'] ?? '';

		if ($mailerId !== '') {
			// Use custom mailer template
			return $this->emailService->sendEmail($mailerId, [
				'email'         => $email,
				'name'          => $userName,
				'resetUrl'      => $resetUrl,
				'expiryMinutes' => $expiryMinutes,
				'collection'    => $collection,
			]);
		}

		// Use default template
		return $this->sendDefaultResetEmail($email, $userName, $resetUrl, $expiryMinutes);
	}

	/**
	 * Send default password reset email using built-in template.
	 *
	 * @return array{success:bool,message:string}
	 */
	private function sendDefaultResetEmail(string $email, string $name, string $resetUrl, int $expiryMinutes): array
	{
		try {
			// Render email template
			$htmlBody = $this->twigEngine->render('email/password-reset.twig', [
				'name'          => $name,
				'resetUrl'      => $resetUrl,
				'expiryMinutes' => $expiryMinutes,
			]);

			// Send email using EmailSender
			return $this->emailSender->send([
				'to'        => $email,
				'toName'    => $name,
				'subject'   => 'Password Reset Request',
				'bodyHtml'  => $htmlBody,
			]);
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Failed to send password reset email: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Find a user by email address in the specified collection.
	 */
	private function findUserByEmail(string $email, string $collection): ?ObjectData
	{
		$users = $this->indexSearcher->searchByProperty($collection, 'email', $email);
		$first = $users->first();

		if ($users->isEmpty() || is_null($first)) {
			return null;
		}

		try {
			return $this->objectFetcher->fetchObject($collection, $first['id']);
		} catch (\Exception) {
			return null;
		}
	}
}
