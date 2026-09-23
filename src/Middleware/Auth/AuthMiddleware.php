<?php

namespace TotalCMS\Middleware\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\LoginRedirector;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\SessionActivityTracker;
use TotalCMS\Domain\Security\CSRF\CSRFRequestValidator;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Auth middleware.
 *
 * Redirects to the login page if the user is not authenticated.
 *
 * Bearer-authenticated requests are passed through without a session check:
 * when OAuthBearerMiddleware (mounted as an outer layer on the /api/ group)
 * successfully validates a JWT it sets the `oauth_access_token_id` request
 * attribute. The presence of that attribute means the request is already
 * authenticated via OAuth — session validation would be redundant and would
 * block legitimate Bearer-only callers that have no browser session.
 */
readonly class AuthMiddleware implements MiddlewareInterface
{
	private string $defaultAuthCollection;
	private LoggerInterface $logger;

	public function __construct(
		private Config $config,
		private AccessManager $accessManager,
		private PersistentLoginService $persistentLoginService,
		private CSRFRequestValidator $csrfValidator,
		private SessionActivityTracker $activity,
		private LoginRedirector $redirector,
		LoggerFactory $loggerFactory,
	) {
		$this->defaultAuthCollection = $this->config->auth['collection'];
		$this->logger                = $loggerFactory->channelLogger(LogChannel::AuthMiddleware);
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (!$this->config->authEnabled()) {
			return $handler->handle($request);
		}

		// Bearer-authenticated requests are already authenticated via OAuthBearerMiddleware
		// upstream. Skip the session check so Bearer-only callers are not blocked.
		if ($request->getAttribute('oauth_access_token_id') !== null) {
			return $handler->handle($request->withAttribute('authMethod', 'oauth_bearer'));
		}

		$this->activity->touch();

		// Try to restore from persistent login if not authenticated
		if (!$this->accessManager->sessionHasUser()) {
			$hasCookie = $this->persistentLoginService->hasPersistentCookie();
			$this->logger->debug('No session user', [
				'has_persistent_cookie' => $hasCookie,
				'path'                  => $request->getUri()->getPath(),
			]);

			$restored = $this->persistentLoginService->restoreFromPersistentToken();
			if (!$restored) {
				$this->logger->debug('Persistent restore failed, redirecting to login');

				return $this->redirectToLogin($request);
			}
			$this->logger->debug('Session restored from persistent token');
		}

		// Require default auth collection
		if (!$this->accessManager->userLoggedIn($this->defaultAuthCollection)) {
			$this->logger->debug('User not in required auth collection', ['collection' => $this->defaultAuthCollection]);

			return $this->redirectToDenied($request);
		}

		// This middleware authenticates via the session cookie — the one auth
		// mode CSRF can ride. State-changing requests must prove they came from
		// us, by same origin or by token. (OAuth Bearer requests exited above.)
		if (!$this->csrfValidator->passes($request)) {
			$this->logger->debug('CSRF validation failed for session-authenticated request', ['path' => $request->getUri()->getPath()]);

			throw new HttpForbiddenException(
				$request,
				'CSRF validation failed. Session-authenticated requests must come from this site, or carry the CSRF token (X-CSRF-Token header or csrf_token field). Use an API key for scripted access.'
			);
		}

		return $handler->handle($request);
	}

	private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
	{
		return $this->redirector->toRoute($request, 'login');
	}

	private function redirectToDenied(ServerRequestInterface $request): ResponseInterface
	{
		return $this->redirector->toRoute($request, 'denied');
	}
}
