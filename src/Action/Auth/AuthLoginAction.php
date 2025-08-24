<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Renderer\TwigRenderer;

final readonly class AuthLoginAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private FirstLoginChecker $firstLoginChecker,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		if (isset($args['collection']) && $this->firstLoginChecker->isNewInstallation()) {
			// If this is a new installation, redirect to the default login page
			$router = RouteContext::fromRequest($request)->getRouteParser();
			$url    = $router->urlFor('login');

			return $response->withStatus(302)->withHeader('Location', $url);
		}

		return $this->twigRenderer->template($response, 'admin/login.twig', $args);
	}
}
