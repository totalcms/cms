<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Dispatches requests to extension-registered route handlers.
 *
 * Route: /ext/{vendor}/{name}/{path}
 * Auth: DualAuth for addRoutes(), none for addPublicRoutes()
 */
readonly class ExtensionRouteAction
{
	public function __construct(
		private ExtensionManager $extensionManager,
		private AccessManager $accessManager,
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private ContainerInterface $container,
		private JsonRenderer $renderer,
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
		$extensionId = ($args['vendor'] ?? '') . '/' . ($args['name'] ?? '');
		$path        = '/' . ltrim($args['path'] ?? '', '/');
		$method      = strtoupper($request->getMethod());

		if (!$this->extensionManager->isEnabled($extensionId)) {
			return $this->renderer->json($response, ['error' => 'Extension not found'])->withStatus(404);
		}

		$routeMatch = $this->extensionManager->matchExtensionRoute($extensionId, $method, $path);
		if (!$routeMatch instanceof ExtensionRoute) {
			return $this->renderer->json($response, ['error' => 'Route not found'])->withStatus(404);
		}

		// Enforce auth for non-public routes
		if (!$routeMatch->public && !$this->isAuthenticated($request)) {
			return $this->renderer->json($response, ['error' => 'Authentication required'])->withStatus(401);
		}

		// Resolve and invoke the handler
		$handler = $routeMatch->handler;
		if (is_string($handler) && class_exists($handler)) {
			$handler = $this->container->get($handler);
		}

		if (is_callable($handler)) {
			return $handler($request, $response, $args);
		}

		return $this->renderer->json($response, ['error' => 'Invalid route handler'])->withStatus(500);
	}

	private function isAuthenticated(ServerRequestInterface $request): bool
	{
		// Check session auth
		if ($this->accessManager->sessionHasUser()) {
			return true;
		}

		// Check API key auth
		return $this->apiKeyAuthenticator->authenticate($request) instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData;
	}
}
