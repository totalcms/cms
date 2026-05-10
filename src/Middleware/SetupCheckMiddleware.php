<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

/**
 * Middleware to check if Total CMS has been set up.
 * If not, redirect to the appropriate setup wizard step.
 *
 * This middleware runs BEFORE Slim's RoutingMiddleware so it can intercept
 * requests for unrouted paths (like `/`) — otherwise Slim would throw 404
 * before this check ever ran. URL prefixes are used instead of route names
 * because the route context isn't populated yet at this point in the chain.
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

		$path = $request->getUri()->getPath();

		// Public assets are always allowed (admin CSS/JS, vendor assets, etc.)
		if (str_starts_with($path, '/api/assets/')) {
			return $handler->handle($request);
		}

		// Setup wizard paths: only accessible while setup is incomplete
		if ($path === '/setup' || str_starts_with($path, '/setup/')) {
			if ($this->setupState->isSetupComplete()) {
				return $this->redirectRenderer->redirectFor(new Response(), 'admin-index');
			}

			return $handler->handle($request);
		}

		// Anything else: allow through when setup is complete
		if ($this->setupState->isSetupComplete()) {
			return $handler->handle($request);
		}

		// Pre-setup, non-wizard request. Only redirect to the wizard for
		// requests that look like page navigation — let asset-like and
		// API requests fall through to routing so they 404 (or 401)
		// naturally. Without this, every unrouted request — `/css/foo.css`,
		// `/api/whatever`, a typo'd image URL — would 302 to the wizard,
		// which breaks browser asset loading and confuses API clients.
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		if ($ext !== '' || str_starts_with($path, '/api/')) {
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
}
