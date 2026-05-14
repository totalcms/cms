<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\EmailVerificationService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * Handle email verification link clicks.
 *
 * GET /admin/verify-email/{token}
 *
 * Validates the token, activates the user account (sets `active = true`),
 * invalidates the token, and redirects to the login page with a flash
 * message. Single-use — the token cannot be replayed.
 *
 * No POST counterpart: the token in the URL is the secret, and the user's
 * intent is implicit in the click. No additional confirmation form needed.
 */
readonly class AuthVerifyEmailAction
{
	public function __construct(
		private EmailVerificationService $verificationService,
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
		$token  = $args['token'] ?? '';
		$flash  = $this->session->getFlash();
		$router = RouteContext::fromRequest($request)->getRouteParser();

		$defaultCollection = (string)($this->config->auth['collection'] ?? 'auth');

		// Validate the token first so we can route the user to the correct
		// login URL (per-collection) before activation. activateUser() will
		// re-validate internally, but it consumes the token, so we'd lose the
		// collection info if we relied solely on that result.
		$validation = $this->verificationService->validateToken($token);
		$collection = (string)($validation->data['collection'] ?? $defaultCollection);

		$result = $this->verificationService->activateUser($token);

		if (!$result->success) {
			// Expired / invalid link — send the user straight to the resend
			// form so they can request a fresh link in one click. Use the
			// collection-scoped form if we successfully decoded the token's
			// collection above; otherwise fall back to the default form.
			$flash->add('error', $this->translator->trans('flash.email_verification_failed'));
			$resendUrl = $collection === $defaultCollection
				? $router->urlFor('resend-verification')
				: $router->urlFor('resend-verification', ['collection' => $collection]);

			return $response->withStatus(302)->withHeader('Location', $resendUrl);
		}

		// Route to the collection-specific login if the user isn't in the
		// default auth collection.
		$loginUrl = $collection === $defaultCollection
			? $router->urlFor('login')
			: $router->urlFor('login', ['collection' => $collection]);

		$flash->add('success', $this->translator->trans('flash.email_verified'));

		return $response->withStatus(302)->withHeader('Location', $loginUrl);
	}
}
