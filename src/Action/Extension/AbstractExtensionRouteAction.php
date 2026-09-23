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
 * Dispatches `/{vendor}/{name}/{path}` to an extension-registered handler:
 * enabled extension → matched route → authorised → handler resolved from
 * the container (or called as-is) with the route's placeholder values merged
 * into the args. Subclasses supply the matcher and, where the route group
 * has no middleware of its own, the credential check.
 */
abstract readonly class AbstractExtensionRouteAction
{
	public function __construct(
		protected ExtensionManager $extensionManager,
		protected ContainerInterface $container,
		protected JsonRenderer $renderer,
	) {
	}

	abstract protected function match(string $extensionId, string $method, string $path): ?ExtensionRoute;

	/** An error response to send instead of the handler, or null to proceed. */
	protected function authorize(ServerRequestInterface $request, ResponseInterface $response, ExtensionRoute $route): ?ResponseInterface
	{
		return null;
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

		if (!$this->extensionManager->isEnabled($extensionId)) {
			return $this->renderer->json($response, ['error' => 'Extension not found'])->withStatus(404);
		}

		$route = $this->match($extensionId, strtoupper($request->getMethod()), $path);
		if (!$route instanceof ExtensionRoute) {
			return $this->renderer->json($response, ['error' => 'Route not found'])->withStatus(404);
		}

		$denied = $this->authorize($request, $response, $route);
		if ($denied instanceof ResponseInterface) {
			return $denied;
		}

		$handler = $route->handler;
		if (is_string($handler) && class_exists($handler)) {
			$handler = $this->container->get($handler);
		}

		if (is_callable($handler)) {
			return $handler($request, $response, array_merge($args, $route->params));
		}

		return $this->renderer->json($response, ['error' => 'Invalid route handler'])->withStatus(500);
	}
}
