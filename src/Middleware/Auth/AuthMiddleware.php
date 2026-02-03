<?php

namespace TotalCMS\Middleware\Auth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Auth middleware.
 *
 * Redirects to the login page if the user is not authenticated.
 */
readonly class AuthMiddleware implements MiddlewareInterface
{
	private string $defaultAuthCollection;
	private LoggerInterface $logger;

	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private PhpSession $session,
		private Config $config,
		private AccessManager $accessManager,
		private PersistentLoginService $persistentLoginService,
		LoggerFactory $loggerFactory,
	) {
		$this->defaultAuthCollection = $this->config->auth['collection'];
		$this->logger                = $loggerFactory->addFileHandler('access.log')->createLogger('auth-middleware');
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		$this->trackSessionActivity();

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

		return $handler->handle($request);
	}

	private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
	{
		return $this->redirectToRoute($request, 'login');
	}

	private function redirectToDenied(ServerRequestInterface $request): ResponseInterface
	{
		return $this->redirectToRoute($request, 'denied');
	}

	private function redirectToRoute(ServerRequestInterface $request, string $route): ResponseInterface
	{
		// Set the current request URL in the session so we can send the user back to it after login
		$this->session->set(SessionKeys::REQUEST_ORIGIN_URL, (string)$request->getUri());
		$this->session->set(SessionKeys::REQUEST_REFERER_URL, $request->getHeaderLine('referer'));

		// User is not logged in. Redirect to login page.
		$routeParser = RouteContext::fromRequest($request)->getRouteParser();
		$url         = $routeParser->urlFor($route);

		return $this->responseFactory->createResponse()
			->withStatus(302)
			->withHeader('Location', $url);
	}

	private function trackSessionActivity(): void
	{
		$now  = time();
		$last = $this->session->get(SessionKeys::LAST_ACTIVITY) ?? $now;
		$max  = $this->config->session['gc_maxlifetime'];

		// Check for persistent login - use hasPersistentLoginOrCookie() to handle
		// the case where session was garbage collected but cookie still exists
		$isPersistentLogin = $this->persistentLoginService->hasPersistentLoginOrCookie();

		$this->session->set(SessionKeys::LAST_ACTIVITY, $now);

		if ($now - $last > $max / 4) {
			// Regenerate session ID every 4th of the max session lifetime
			$this->session->regenerateId();
		}

		// Check if session has expired (skip check for persistent logins)
		if ($now - $last > $max) {
			if ($isPersistentLogin) {
				// Clean up expired persistent tokens periodically
				$this->persistentLoginService->cleanupExpiredTokens();

				return;
			}
			// Session has expired for non-persistent login. Clear and destroy the session.
			$this->session->clear();
			$this->session->destroy();
		}
	}
}
