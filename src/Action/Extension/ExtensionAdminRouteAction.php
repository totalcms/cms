<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Dispatches requests to extension-registered admin route handlers.
 *
 * Route: /admin/ext/{vendor}/{name}/{path}
 * Auth: handled by admin middleware on the route group.
 */
readonly class ExtensionAdminRouteAction
{
	public function __construct(
		private ExtensionManager $extensionManager,
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

		$routeMatch = $this->extensionManager->matchExtensionAdminRoute($extensionId, $method, $path);
		if (!$routeMatch instanceof ExtensionRoute) {
			return $this->renderer->json($response, ['error' => 'Route not found'])->withStatus(404);
		}

		$handler = $routeMatch->handler;
		if (is_string($handler) && class_exists($handler)) {
			$handler = $this->container->get($handler);
		}

		if (is_callable($handler)) {
			return $handler($request, $response, $args);
		}

		return $this->renderer->json($response, ['error' => 'Invalid route handler'])->withStatus(500);
	}
}
