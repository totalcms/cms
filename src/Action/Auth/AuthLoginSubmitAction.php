<?php

namespace TotalCMS\Action\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Exception\AccountNotActiveException;
use TotalCMS\Domain\Auth\Exception\InvalidCredentialsException;
use TotalCMS\Domain\Auth\Service\AuthThrottle;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\OAuth\Service\OAuthLoginCollection;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;
use TotalCMS\Support\SameOriginRedirect;

/**
 * Action.
 */
readonly class AuthLoginSubmitAction
{
	public function __construct(
		private PhpSession $session,
		private LoginService $loginService,
		private SessionLogin $sessionLogin,
		private Config $config,
		private PersistentLoginService $persistentLoginService,
		private TranslationService $translator,
		private OAuthLoginCollection $oauthLogin,
		private AuthThrottle $throttle,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data = (array)$request->getParsedBody();

		// Clear all flash messages
		$flash = $this->session->getFlash();
		$flash->clear();

		// Counted per IP on the server (AuthThrottle), not in the session: a bot
		// that drops its cookie must not get a fresh count on every request.
		// A locked-out client is not checked against any password at all.
		if ($this->throttle->loginLockedFor($request) > 0) {
			$flash->add('error', $this->translator->trans('flash.login_too_many_attempts'));
		} elseif (!isset($data['email'], $data['password'])) {
			// throw new HttpUnauthorizedException($request, 'Email and password are required');
			$flash->add('error', $this->translator->trans('flash.login_credentials_required'));
		}

		$router = RouteContext::fromRequest($request)->getRouteParser();
		$url    = $router->urlFor('login');

		if (isset($args['collection'])) {
			$url = $router->urlFor('login', ['collection' => $args['collection']]);
		}

		if ($flash->has('error')) {
			if ($this->session->has(SessionKeys::LOGIN_ORIGIN)) {
				$url = $this->session->get(SessionKeys::LOGIN_ORIGIN);
			}

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// The form field is always named `email` for backwards compatibility,
		// but the value may be an ID instead of an email depending on the
		// `auth.loginWith` config. UserValidationService dispatches transparently.
		$idOrEmail       = $data['email'];
		$password        = $data['password'];
		$persistentLogin = !empty($data['persistent_login']);
		$collection      = $args['collection'] ?? '';

		// On the OAuth login page, mid-way through connecting an AI client,
		// staff from the default collection can sign in too.
		$fallback = $this->oauthLogin->fallsBackToDefault($collection, (string)$this->session->get(SessionKeys::REQUEST_ORIGIN_URL, ''));

		$account = $collection . '|' . $idOrEmail;
		$this->throttle->slowDownAccount($account);

		$user = null;
		try {
			$user = $this->loginService->authenticate($idOrEmail, $password, $collection, $fallback);
		} catch (AccountNotActiveException $e) {
			// Distinct flag so the login template can surface a "resend
			// verification email" link inline with the error. The generic
			// error message still shows alongside.
			$flash->add('error', $e->getMessage());
			$flash->add('account_not_active', '1');
		} catch (InvalidCredentialsException) {
			// One message for "no such account" and "wrong password".
			$flash->add('error', $this->translator->trans('flash.login_invalid'));
		} catch (\Exception $e) {
			// throw new HttpUnauthorizedException($request, $e->getMessage());
			$flash->add('error', $e->getMessage());
		}

		// Check if authentication failed and redirect back
		if (!isset($user, $user['id'])) {
			$this->recordFailure($request, $account);

			if ($this->session->has(SessionKeys::LOGIN_ORIGIN)) {
				$url = $this->session->get(SessionKeys::LOGIN_ORIGIN);
			}

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		// Authentication succeeded - check for redirect URL in multiple places:
		// 1. POST data (from login form with redirect parameter)
		// 2. Query parameter (for direct links)
		// 3. Session storage (for direct login)
		// 4. Default to admin index
		$postData    = (array)$request->getParsedBody();
		$queryParams = $request->getQueryParams();
		$redirectUrl = $postData['redirect'] ?? $queryParams['redirect'] ?? $this->session->get(SessionKeys::REQUEST_ORIGIN_URL, $router->urlFor('admin-index'));

		// Open-redirect guard: the redirect target can arrive via a crafted
		// link (?redirect=https://evil.example), and a login page that
		// forwards wherever it's told is a phishing primitive. Only local
		// paths and same-host absolute URLs survive; anything else falls
		// back to the dashboard.
		$url = $this->sameOriginRedirect((string)$redirectUrl, $request->getUri()->getHost(), $router->urlFor('admin-index'));

		$this->session->destroy();
		$this->session->start();
		$this->session->regenerateId();

		// For SuperAdmin cross-collection authentication, use the collection they were authenticated against
		$sessionCollection = $user['_authenticated_collection'] ?? $collection;

		// `$collection` is empty on the plain `/admin/login` route (no
		// `{collection}` argument), so store the configured default rather than
		// an empty string. Readers that compare the session value literally —
		// the self-profile carve-out in CollectionAccessMiddleware — otherwise
		// never match, and the user is denied writes to their own record.
		if ((string)$sessionCollection === '') {
			$sessionCollection = (string)$this->config->auth['collection'];
		}

		$this->sessionLogin->establish((string)$user['id'], (string)$sessionCollection, $persistentLogin);
		$this->throttle->recordLoginSuccess($request, $account);

		// If persistent login is checked, create persistent token
		if ($persistentLogin) {
			$this->persistentLoginService->createPersistentToken();
		}

		$flash->add('success', $this->translator->trans('flash.login_success'));

		return $response->withStatus(302)->withHeader('Location', $url);
	}

	/** Count a failed login; say so when it started a lockout. */
	private function recordFailure(ServerRequestInterface $request, string $account): void
	{
		if ($this->throttle->recordLoginFailure($request, $account) > 0) {
			$this->session->getFlash()->add('error', $this->translator->trans('flash.login_too_many_attempts'));
		}
	}

	/**
	 * Allow a post-login redirect only to this site — the rule lives in
	 * {@see SameOriginRedirect}, shared with the verification redirect.
	 */
	private function sameOriginRedirect(string $candidate, string $requestHost, string $fallback): string
	{
		return SameOriginRedirect::resolve($candidate, $requestHost, $fallback);
	}
}
