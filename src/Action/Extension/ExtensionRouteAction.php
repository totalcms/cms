<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Security\CSRF\CSRFRequestValidator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Dispatches requests to extension-registered API route handlers.
 *
 * Route: /ext/{vendor}/{name}/{path}
 *
 * These routes are mounted with no middleware, so auth is enforced here:
 * session or API key for addRoutes(), none for addPublicRoutes(). Session-
 * authorised writes additionally go through the CSRF policy — see authorize().
 */
readonly class ExtensionRouteAction extends AbstractExtensionRouteAction
{
	public function __construct(
		ExtensionManager $extensionManager,
		private AccessManager $accessManager,
		private ApiKeyAuthenticator $apiKeyAuthenticator,
		ContainerInterface $container,
		JsonRenderer $renderer,
		private CSRFRequestValidator $csrfValidator,
	) {
		parent::__construct($extensionManager, $container, $renderer);
	}

	protected function match(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		return $this->extensionManager->matchExtensionRoute($extensionId, $method, $path);
	}

	/**
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
	protected function authorize(ServerRequestInterface $request, ResponseInterface $response, ExtensionRoute $route): ?ResponseInterface
	{
		if ($route->public) {
			return null;
		}

		if ($this->accessManager->sessionHasUser()) {
			if (!$this->csrfValidator->passes($request)) {
				return $this->renderer->json($response, [
					'error' => 'CSRF validation failed. Session-authenticated requests must come from this site, or carry the CSRF token. Use an API key for scripted access.',
				])->withStatus(403);
			}

			return null;
		}

		if ($this->apiKeyAuthenticator->authenticate($request) instanceof ApiKeyData) {
			return null;
		}

		return $this->renderer->json($response, ['error' => 'Authentication required'])->withStatus(401);
	}
}
