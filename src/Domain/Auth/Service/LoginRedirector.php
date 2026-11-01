<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Send a visitor to a named auth route (login, denied) and remember where
 * they were, so the login flow can return them there afterwards.
 *
 * An /api route answers in kind instead of redirecting: a script or an API
 * client gets a JSON 401 or 403, not the HTML login page, and an htmx request
 * from an admin page whose session ran out gets an HX-Redirect, so the whole
 * page goes to the login and comes back to that page afterwards.
 */
final readonly class LoginRedirector
{
	private const API_ERRORS = [
		'login'  => [401, 'Sign in to the admin, or send an API key in the X-API-Key header.'],
		'denied' => [403, 'This account cannot use the admin API.'],
	];

	public function __construct(
		private SessionInterface $session,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function toRoute(ServerRequestInterface $request, string $route): ResponseInterface
	{
		$routeContext = RouteContext::fromRequest($request);
		$url          = $routeContext->getRouteParser()->urlFor($route);
		$pattern      = $routeContext->getRoute()?->getPattern() ?? '';

		if (!str_starts_with($pattern, '/api/') || !isset(self::API_ERRORS[$route])) {
			$this->remember((string)$request->getUri(), $request->getHeaderLine('referer'));

			return $this->responseFactory->createResponse()
				->withStatus(302)
				->withHeader('Location', $url);
		}

		[$status, $message] = self::API_ERRORS[$route];

		if ($request->getHeaderLine('HX-Request') === 'true') {
			// Return to the page the request came from, never to another host.
			$page = $request->getHeaderLine('HX-Current-URL');
			if ($page !== '' && parse_url($page, PHP_URL_HOST) === $request->getUri()->getHost()) {
				$this->remember($page, $page);
			}

			return $this->responseFactory->createResponse($status)->withHeader('HX-Redirect', $url);
		}

		$response = $this->responseFactory->createResponse($status)->withHeader('Content-Type', 'application/json');
		$response->getBody()->write((string)json_encode(['error' => $message]));

		return $response;
	}

	private function remember(string $origin, string $referer): void
	{
		$this->session->set(SessionKeys::REQUEST_ORIGIN_URL, $origin);
		$this->session->set(SessionKeys::REQUEST_REFERER_URL, $referer);
	}
}
