<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Bundle\Service\BundleChecker;

/**
 * Stacks Preview middleware.
 *
 * A special middleware that allows to preview a page by passing the "route" query parameter.
 */
final class BundleMiddleware implements MiddlewareInterface
{
	public function __construct(
		private BundleChecker $bundleChecker,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$method = $request->getMethod();

		if ($method !== 'GET') {
			$this->bundleChecker->check();
		}

		$response = $handler->handle($request);

		return $response;
	}
}
