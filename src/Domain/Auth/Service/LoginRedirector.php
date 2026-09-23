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
 */
final readonly class LoginRedirector
{
	public function __construct(
		private SessionInterface $session,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function toRoute(ServerRequestInterface $request, string $route): ResponseInterface
	{
		$this->session->set(SessionKeys::REQUEST_ORIGIN_URL, (string)$request->getUri());
		$this->session->set(SessionKeys::REQUEST_REFERER_URL, $request->getHeaderLine('referer'));

		$url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route);

		return $this->responseFactory->createResponse()
			->withStatus(302)
			->withHeader('Location', $url);
	}
}
