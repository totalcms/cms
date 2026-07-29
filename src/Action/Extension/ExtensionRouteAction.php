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
use TotalCMS\Domain\Security\CSRF\CSRFRequestValidator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Dispatches requests to extension-registered route handlers.
 *
 * Route: /ext/{vendor}/{name}/{path}
 *
 * These routes are mounted with no middleware, so auth is enforced here:
 * session or API key for addRoutes(), none for addPublicRoutes(). Session-
 * authorised writes additionally go through the CSRF policy — see authorize().
 */
readonly class ExtensionRouteAction
{
	public function __construct(
		private ExtensionManager $extensionManager,
		private AccessManager $accessManager,
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		private ContainerInterface $container,
		private JsonRenderer $renderer,
		private CSRFRequestValidator $csrfValidator,
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
		if (!$routeMatch->public) {
			$denied = $this->authorize($request, $response);
			if ($denied instanceof ResponseInterface) {
				return $denied;
			}
		}

		// Resolve and invoke the handler
		$handler = $routeMatch->handler;
		if (is_string($handler) && class_exists($handler)) {
			$handler = $this->container->get($handler);
		}

		if (is_callable($handler)) {
			// Merge any {placeholder} values captured from the registered
			// route path (e.g. /s/{id}) into the handler's args.
			return $handler($request, $response, array_merge($args, $routeMatch->params));
		}

		return $this->renderer->json($response, ['error' => 'Invalid route handler'])->withStatus(500);
	}

	/**
	 * Authorise a non-public extension route. Returns an error response to send,
	 * or null to proceed.
	 *
	 * These routes are mounted without middleware (config/routes/api/ext.php),
	 * so this is the only place their credentials are checked — including CSRF.
	 * Session-cookie auth is the one credential CSRF can ride, so a session-
	 * authorised write must also prove it came from this site.
	 *
	 * Extensions need no code changes for this: a same-origin request passes on
	 * the browser's Origin header alone, which is exactly why the token-only
	 * scheme couldn't close this gap without breaking every third-party
	 * extension's JS.
	 */
	private function authorize(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
	{
		if ($this->accessManager->sessionHasUser()) {
			if (!$this->csrfValidator->passes($request)) {
				return $this->renderer->json($response, [
					'error' => 'CSRF validation failed. Session-authenticated requests must come from this site, or carry the CSRF token. Use an API key for scripted access.',
				])->withStatus(403);
			}

			return null;
		}

		if ($this->apiKeyAuthenticator->authenticate($request) instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
			return null;
		}

		return $this->renderer->json($response, ['error' => 'Authentication required'])->withStatus(401);
	}
}
