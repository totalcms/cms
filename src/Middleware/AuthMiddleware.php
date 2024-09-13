<?php

namespace TotalCMS\Middleware;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Auth middleware.
 *
 * Redirects to the login page if the user is not authenticated.
 */
final class AuthMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private PhpSession $session
	) {}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->session->get('user')) {
			// User is logged in
			return $handler->handle($request);
		}

		// Set the current request URL in the session so we can send the user back to it after login
		$this->session->set('requestOriginUrl', (string)$request->getUri());

		// User is not logged in. Redirect to login page.
		$routeParser = RouteContext::fromRequest($request)->getRouteParser();
		$url = $routeParser->urlFor('login');

		return $this->responseFactory->createResponse()
			->withStatus(302)
			->withHeader('Location', $url);
	}
}
