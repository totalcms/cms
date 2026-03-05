<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

/**
 * Middleware to check if Total CMS has been set up (tcms-data folder exists).
 * If not, redirect to the setup page.
 *
 * This middleware runs BEFORE authentication to allow initial setup without auth.
 */
readonly class SetupCheckMiddleware implements MiddlewareInterface
{
	public function __construct(
		private Config $config,
		private RedirectRenderer $redirectRenderer,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		// Skip setup check entirely in preview environment
		// Preview uses pre-configured datadir and doesn't need setup workflow
		if ($this->config->env === 'preview') {
			return $handler->handle($request);
		}

		// Get the matched route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		// Skip setup check for setup routes and public assets
		if ($route instanceof \Slim\Interfaces\RouteInterface) {
			$routeName = $route->getName();
			if ($routeName !== null && (str_starts_with($routeName, 'setup-') || $routeName === 'public-asset')) {
				return $handler->handle($request);
			}

			// Allow login routes if data directory exists (for first user creation)
			// even if auth collection doesn't exist yet
			// Check route path pattern since POST login route may not have a name
			if ($this->dataDirBasicExists()) {
				$routePattern = $route->getPattern();
				if ($routeName === 'login' || $routePattern === '/login[/{collection}]') {
					return $handler->handle($request);
				}
			}
		}

		// Check if tcms-data exists in any of the expected locations
		if ($this->dataDirExists()) {
			// Data directory exists, continue normal flow
			return $handler->handle($request);
		}

		// Data directory doesn't exist, redirect to setup page
		return $this->redirectRenderer->redirectFor(new Response(), 'setup-data-path');
	}

	/**
	 * Check if tcms-data directory exists (basic check).
	 *
	 * Used to allow login page access for first user creation.
	 */
	private function dataDirBasicExists(): bool
	{
		return $this->config->datadir !== '' && is_dir($this->config->datadir);
	}

	/**
	 * Check if tcms-data directory is properly set up.
	 *
	 * A directory is considered "set up" if it contains an auth collection,
	 * which indicates that the user has completed setup and created their first account.
	 * System files like .system/access-groups.json are auto-created and don't count.
	 */
	private function dataDirExists(): bool
	{
		if (!$this->dataDirBasicExists()) {
			return false;
		}

		// Check if auth collection exists (indicates setup is complete)
		$authCollection = $this->config->auth['collection'] ?? 'auth';
		$authPath       = $this->config->datadir . '/' . $authCollection;

		return is_dir($authPath);
	}
}
