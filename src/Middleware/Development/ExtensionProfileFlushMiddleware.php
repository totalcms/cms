<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Development;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;

/**
 * Flushes accumulated extension timing to cache after the response is built.
 * Registered OUTERMOST so its after-handle code runs LAST — after all
 * extension hooks (Twig functions, listeners, boot) have fired.
 */
final readonly class ExtensionProfileFlushMiddleware implements MiddlewareInterface
{
	public function __construct(private ExtensionProfiler $profiler)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);
		$this->profiler->flush();
		return $response;
	}
}
