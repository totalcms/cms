<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Object\ObjectSaveAction;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\AuthMailer;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\SameOriginRedirect;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Public registration endpoint. Creates a user in an opted-in auth collection,
 * and either auto-logs them in OR sends an email verification link, depending
 * on the collection's `requireEmailVerification` flag. Returns JSON in the
 * same shape as {@see ObjectSaveAction} so the
 * standard form builder (`cms.form.builder('members', {register: true})`) can
 * chain deferred image uploads and post-save actions against the new record
 * without any special casing on the client side.
 *
 * The collection MUST be open to registration — listed in
 * `$config->auth['publicRegistration']` OR with **Allow Public Registration**
 * on in its settings (see {@see RegistrationCollections}) — otherwise the
 * endpoint throws a 403. Both are off by default so the default admin auth
 * collection isn't accidentally exposed to public signups.
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
 *  - Anyone who reaches this endpoint can create a user record. The form
 *    builder's `botProtection` option (honeypot + signed time trap, see
 *    {@see BotSubmissionGate}) turns away naive bots with a decoy success —
 *    no record, no email; turn on the collection's Require Bot Protection
 *    to refuse posts that skip it. A determined bot that renders the form
 *    still gets through: verification stops it logging in, not signing up.
 *  - New users land in the global `publicRegistrationGroup` plus the
 *    collection's Registration Groups. If those groups reach gated content,
 *    every signup (including bot signups) gains that access once they verify.
 *  - Password validation (minimum length etc.) is the schema's responsibility.
 */
readonly class AuthRegisterSubmitAction
{
	/**
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList") one collaborator per
	 * concern of a public signup: save, sign-in, field policy, verification
	 * mail, registration gate and bot check.
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectSaver $objectSaver,
		private LoginService $loginService,
		private SessionLogin $sessionLogin,
		private AccessGuard $guard,
		private ActorContext $actors,
		private CollectionFetcher $collectionFetcher,
		private EmailVerificationService $verificationService,
		private AuthMailer $mailer,
		private Config $config,
		private RegistrationCollections $registrationCollections,
		private BotSubmissionGate $botGate,
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

		if (!$this->registrationCollections->isEnabled($collection)) {
			// 403 with a generic message — don't leak whether the collection
			// exists. Slim's error handler renders it as JSON for AJAX clients.
			throw new HttpForbiddenException($request, 'Public registration is not enabled for this collection.');
		}

		$data = (array)$request->getParsedBody();

		// Where the verification link should land, set by the site's register
		// form (`verifyRedirect`). It belongs to the verification token, never
		// to the user record, so it leaves $data before the save. Off-site
		// targets are dropped: the link then lands on the login page as before.
		$verifyRedirect = SameOriginRedirect::resolve(
			is_string($data['verifyRedirect'] ?? null) ? $data['verifyRedirect'] : '',
			$request->getUri()->getHost(),
			'',
		);
		unset($data['verifyRedirect']);

		// Honeypot + time trap. Checked before anything is saved; the two bot
		// fields leave $data whether or not the check ran.
		// An expired token is a real form left open too long: say so.
		[$data, $botReason] = $this->botGate->screen($request, $data, $collection, 'register');
		if (BotSubmissionGate::expired($botReason)) {
			return $this->botGate->expiredResponse($request, $response);
		}

		// A public registrant may NEVER set privileged fields (groups, active,
		// expiration, maxLoginCount, passkeys) — strip them, then assign our own
		// server-side defaults below. Without this, anyone could self-register as
		// `groups:['admin']` + `active:true`.
		$data = $this->guard->stripProtected($collection, $data);

		// Branch on the collection's verification flag. We must fetch the
		// collection BEFORE saving so we know whether to force-disable the
		// new account.
		$collectionData         = $this->collectionFetcher->fetchCollection($collection);
		$requiresVerification   = $collectionData instanceof CollectionData && $collectionData->requireEmailVerification;

		// Force account state server-side in ALL cases (previously `active` was
		// only forced under verification, leaving it form-controllable otherwise):
		// inactive until verified, active immediately when verification is off.
		$data['active'] = !$requiresVerification;

		// Force the server-side groups: the global `publicRegistrationGroup` plus
		// the collection's Registration Groups. Neither set => no groups, so a
		// registrant gains no group-gated access until an operator grants it.
		$data['groups'] = $this->registrationCollections->groupsFor($collection);

		if ($botReason !== '') {
			return $this->botDecoy($response, $collection, $data, $requiresVerification);
		}

		// Saved as the system: `active` and `groups` are server values the
		// anonymous registrant may not write. Errors bubble to Slim's handler.
		$user = $this->actors->runAs(Actor::system(), fn () => $this->objectSaver->saveObject($collection, $data));

		if ($requiresVerification) {
			$email = trim((string)($data['email'] ?? ''));
			if ($email !== '') {
				$this->sendVerificationEmail($email, $collection, (string)($data['name'] ?? ''), $verifyRedirect);
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
	 * A bot gets the response a real signup gets — the user it sent, with the
	 * "check your email" flag when the collection verifies — but nothing is
	 * saved, no one is signed in and no email goes out.
	 *
	 * @param array<string,mixed> $data
	 */
	private function botDecoy(ResponseInterface $response, string $collection, array $data, bool $requiresVerification): ResponseInterface
	{
		$decoy = $this->botGate->decoy($collection, $data);

		return $requiresVerification
			? $this->renderer->jsonItem($response, $decoy, new ObjectMetaTransformer(), ['requiresVerification' => true])
			: $this->renderer->jsonItem($response, $decoy, new ObjectMetaTransformer());
	}

	/**
	 * Send a verification email. Failures do not bubble up — the account
	 * exists either way, and the user can be re-sent a link via the
	 * resend-verification flow.
	 */
	private function sendVerificationEmail(string $email, string $collection, string $name, string $redirect): void
	{
		$tokenResult = $this->verificationService->createVerificationToken($email, $collection, $redirect);

		if (!$tokenResult->success || !isset($tokenResult->data['token'])) {
			return;
		}

		$this->mailer->sendVerification($email, (string)$tokenResult->data['token'], $collection, $name);
	}
}
