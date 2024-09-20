<?php

namespace TotalCMS\Middleware;

use Odan\Session\PhpSession;
use TotalCMS\Support\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\UserValidationService;

/**
 * Auth middleware.
 *
 * Redirects to the login page if the user is not authenticated.
 */
final class AuthMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private UserValidationService $userValidationService,
		private PhpSession $session,
		private Config $config,
	) {}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		$this->trackSessionActivity();

		if ($this->session->has('user')) {
			try {
				$user = $this->userValidationService->validateUserById($this->session->get('user'));
				if ($user) {
					return $handler->handle($request);
				}
			} catch (\Exception $e) {
				// User not found. Clear the session and regenerate the session ID.
				$this->session->clear();
				$this->session->regenerateId();
			}
		}

		// Set the current request URL in the session so we can send the user back to it after login
		$this->session->set('requestOriginUrl', (string)$request->getUri());

		// User is not logged in. Redirect to login page.
		$routeParser = RouteContext::fromRequest($request)->getRouteParser();
		$url         = $this->config->api . $routeParser->urlFor('login');

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
