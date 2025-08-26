<?php

namespace TotalCMS\Middleware;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Support\Config;

/**
 * Auth middleware.
 *
 * Redirects to the login page if the user is not authenticated.
 */
readonly class AuthMiddleware implements MiddlewareInterface
{
	private readonly string $defaultAuthCollection;

	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private PhpSession $session,
		private Config $config,
		private AccessManager $accessManager,
	) {
		$this->defaultAuthCollection = $this->config->auth['collection'];
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		$this->trackSessionActivity();

		if (!$this->accessManager->sessionHasUser()) {
			return $this->redirectToLogin($request);
		}

		// Require default auth collection
		if (!$this->accessManager->userLoggedIn($this->defaultAuthCollection)) {
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
		$this->session->set('requestOriginUrl', (string)$request->getUri());
		$this->session->set('requestRefererUrl', $request->getHeaderLine('referer'));

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
		$last = $this->session->get('last_activity') ?? $now;
		$max  = $this->config->session['gc_maxlifetime'];

		$this->session->set('last_activity', $now);

		if ($now - $last > $max / 4) {
			// Regenerate session ID every 4th of the max session lifetime
			$this->session->regenerateId();
		}

		if ($now - $last > $max) {
			// Session has expired. Clear and destroy the session.
			$this->session->clear();
			$this->session->destroy();
		}
	}
}
