<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Public registration endpoint. Creates a user in an opted-in auth collection,
 * and either auto-logs them in OR sends an email verification link, depending
 * on the collection's `requireEmailVerification` flag. Returns JSON in the
 * same shape as {@see \TotalCMS\Action\Object\ObjectSaveAction} so the
 * standard form builder (`cms.form.builder('members', {register: true})`) can
 * chain deferred image uploads and post-save actions against the new record
 * without any special casing on the client side.
 *
 * The collection MUST appear in `$config->auth['publicRegistration']` —
 * otherwise the endpoint throws a 403. That allow-list is empty by default so
 * the default admin auth collection isn't accidentally exposed to public
 * signups.
 *
 * Two flows controlled by the collection's `requireEmailVerification` meta:
 *  - **false** (default): account is created with whatever `active` value the
 *    form submitted (typically true via schema default), then the user is
 *    auto-logged in. Backwards-compatible.
 *  - **true**: account is forced inactive (`active = false`), a verification
 *    email is sent, and the user is NOT logged in. Response JSON carries a
 *    `meta.requiresVerification = true` flag so the form builder can surface
 *    a "check your email" message instead of treating it as a successful
 *    login.
 *
 * Security caveats the operator owns:
 *  - Anyone who reaches this endpoint can create a user record. Add a CAPTCHA
 *    or rate limit even with email verification enabled — verification stops
 *    a bot from logging in, but it doesn't stop them from filling the user
 *    table with junk records.
 *  - New users land in whatever default access group the auth collection's
 *    schema assigns. If that group reaches gated content, every signup
 *    (including bot signups) gains that access once they verify.
 *  - Password validation (minimum length etc.) is the schema's responsibility.
 */
readonly class AuthRegisterSubmitAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectSaver $objectSaver,
		private LoginService $loginService,
		private SessionLogin $sessionLogin,
		private CollectionFetcher $collectionFetcher,
		private EmailVerificationService $verificationService,
		private EmailService $emailService,
		private EmailSender $emailSender,
		private TwigEngine $twigEngine,
		private Config $config,
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
		$collection = $args['collection'] ?? (string)($this->config->auth['collection'] ?? '');
		$allowed    = (array)($this->config->auth['publicRegistration'] ?? []);

		if (!in_array($collection, $allowed, true)) {
			// 403 with a generic message — don't leak whether the collection
			// exists. Slim's error handler renders it as JSON for AJAX clients.
			throw new HttpForbiddenException($request, 'Public registration is not enabled for this collection.');
		}

		$data = (array)$request->getParsedBody();

		// Branch on the collection's verification flag. We must fetch the
		// collection BEFORE saving so we know whether to force-disable the
		// new account.
		$collectionData         = $this->collectionFetcher->fetchCollection($collection);
		$requiresVerification   = $collectionData !== null && $collectionData->requireEmailVerification === true;

		if ($requiresVerification) {
			// Force the new account inactive regardless of what the form
			// submitted. The user becomes active only after clicking the link.
			$data['active'] = false;
		}

		// Save the user record. Errors (validation, duplicate id, etc.) bubble
		// up to Slim's error handler — same as ObjectSaveAction.
		$user = $this->objectSaver->saveObject($collection, $data);

		if ($requiresVerification) {
			$email = trim((string)($data['email'] ?? ''));
			if ($email !== '') {
				$this->sendVerificationEmail($email, $collection, (string)($data['name'] ?? ''));
			}

			// Skip auto-login; respond with the saved user plus a verification
			// flag the form builder reads to surface a "check your email"
			// message.
			return $this->renderer->jsonItem(
				$response,
				$user,
				new ObjectMetaTransformer(),
				['requiresVerification' => true],
			);
		}

		// Auto-login: best-effort. If it throws (auth backend down, race with a
		// just-created record, etc.), the account still exists and the client
		// can prompt the user to sign in. Image uploads in the form builder
		// chain may then 401 if they require auth — that's the price of
		// keeping the save and the session establishment loosely coupled.
		try {
			$authenticated = $this->loginService->authenticate(
				(string)($data['email'] ?? ''),
				(string)($data['password'] ?? ''),
				$collection,
			);
			$this->sessionLogin->establish((string)$authenticated['id'], $collection);
		} catch (\Throwable) {
			// Silent: response still includes the saved user.
		}

		return $this->renderer->jsonItem($response, $user, new ObjectMetaTransformer());
	}

	/**
	 * Send a verification email. Failures are logged inside the service layer
	 * and do not bubble up — the account exists either way, and the user can
	 * be re-sent a link via the forgot-password flow (which works for inactive
	 * accounts because it only validates the token, not the user state).
	 */
	private function sendVerificationEmail(string $email, string $collection, string $name): void
	{
		$tokenResult = $this->verificationService->createVerificationToken($email, $collection);

		if (!$tokenResult->success || !isset($tokenResult->data['token'])) {
			return;
		}

		$token = (string)$tokenResult->data['token'];
		// Mirror the URL-building pattern in ForgotPasswordSubmitAction so the
		// link is absolute and includes both the configured site URL and the
		// API prefix the admin lives under.
		$verifyUrl = $this->config->url . $this->config->api . '/admin/verify-email/' . $token;

		$expiryMinutes = (int)($this->config->auth['verificationTokenExpiry'] ?? 1440);

		$mailerId = (string)($this->config->auth['verificationMailerId'] ?? '');

		if ($mailerId !== '') {
			$this->emailService->sendEmail($mailerId, [
				'email'         => $email,
				'name'          => $name,
				'verifyUrl'     => $verifyUrl,
				'expiryMinutes' => $expiryMinutes,
				'collection'    => $collection,
			]);

			return;
		}

		$this->sendDefaultVerificationEmail($email, $name, $verifyUrl, $expiryMinutes);
	}

	private function sendDefaultVerificationEmail(string $email, string $name, string $verifyUrl, int $expiryMinutes): OperationResult
	{
		try {
			$htmlBody = $this->twigEngine->render('email/verify-email.twig', [
				'name'          => $name,
				'verifyUrl'     => $verifyUrl,
				'expiryMinutes' => $expiryMinutes,
			]);

			return $this->emailSender->send([
				'to'       => $email,
				'toName'   => $name,
				'subject'  => 'Verify Your Email',
				'bodyHtml' => $htmlBody,
			]);
		} catch (\Exception $e) {
			return OperationResult::failure('Failed to send verification email: ' . $e->getMessage());
		}
	}
}
