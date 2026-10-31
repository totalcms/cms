<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Closes the setup wizard once setup is complete.
 *
 * SetupCheckMiddleware does this too, but it runs before routing and decides
 * by the path's spelling; on the route group this runs after the router has
 * matched, so no spelling of the URL can reach a wizard step. The two used to
 * disagree: `/%73etup/account` passed the path check and let an anonymous
 * visitor create an admin on a finished install (security scan, 2026-10-30).
 */
readonly class SetupWizardOpenMiddleware implements MiddlewareInterface
{
	public function __construct(
		private SetupStateManager $setupState,
		private RedirectRenderer $redirectRenderer,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->setupState->isSetupComplete()) {
			return $this->redirectRenderer->redirectFor(new Response(), 'admin-index');
		}

		return $handler->handle($request);
	}
}
