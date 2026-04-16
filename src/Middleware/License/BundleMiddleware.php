<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Bundle\Service\BundleChecker;

/**
 * Stacks Preview middleware.
 *
 * A special middleware that allows to preview a page by passing the "route" query parameter.
 */
readonly class BundleMiddleware implements MiddlewareInterface
{
	public function __construct(
		private BundleChecker $bundleChecker,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getMethod();

		// Skip bundle check during setup to prevent premature file creation
		if ($method !== 'GET' && !$this->isSetupRoute($request)) {
			$this->bundleChecker->check();
		}

		return $handler->handle($request);
	}

	/**
	 * Check if the current request is a setup route.
	 */
	private function isSetupRoute(ServerRequestInterface $request): bool
	{
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		if ($route instanceof \Slim\Interfaces\RouteInterface) {
			$routeName = $route->getName();

			return $routeName !== null && str_starts_with($routeName, 'setup-');
		}

		return false;
	}
}
