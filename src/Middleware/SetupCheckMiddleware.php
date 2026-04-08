<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

/**
 * Middleware to check if Total CMS has been set up.
 * If not, redirect to the appropriate setup wizard step.
 *
 * This middleware runs BEFORE authentication to allow initial setup without auth.
 */
readonly class SetupCheckMiddleware implements MiddlewareInterface
{
	public function __construct(
		private Config $config,
		private RedirectRenderer $redirectRenderer,
		private SetupStateManager $setupState,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		// Skip setup check entirely in preview environment
		if ($this->config->env === 'preview') {
			return $handler->handle($request);
		}

		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		// Skip setup check for setup routes and public assets
		if ($route instanceof \Slim\Interfaces\RouteInterface) {
			$routeName = $route->getName();
			if ($routeName !== null && (str_starts_with($routeName, 'setup-') || $routeName === 'public-asset')) {
				return $handler->handle($request);
			}
		}

		// If setup is complete (auth collection exists), allow normal flow
		if ($this->setupComplete()) {
			return $handler->handle($request);
		}

		// Setup not complete — redirect to welcome or current wizard step
		$currentStep = $this->setupState->getCurrentStep();

		// If on the very first step, show the welcome page instead
		if ($currentStep === 'setup-environment' && !$this->setupState->isStepComplete('environment')) {
			return $this->redirectRenderer->redirectFor(new Response(), 'setup-welcome');
		}

		return $this->redirectRenderer->redirectFor(new Response(), $currentStep);
	}

	/**
	 * Setup is considered complete when the auth collection exists.
	 * This is the definitive check — once an admin user is created, setup is done.
	 */
	private function setupComplete(): bool
	{
		if ($this->config->datadir === '' || !is_dir($this->config->datadir)) {
			return false;
		}

		$authCollection = $this->config->auth['collection'] ?? 'auth';
		$authPath       = $this->config->datadir . '/' . $authCollection;

		return is_dir($authPath);
	}
}
