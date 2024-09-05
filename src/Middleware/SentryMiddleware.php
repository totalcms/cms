<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SentryMiddleware implements MiddlewareInterface
{
	/**
	 * The constructor.
	 *
	 * @param array<string,mixed> $options The sentry options
	 */
	public function __construct(private array $options)
	{
	}

	/**
	 * Invoke middleware.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param RequestHandlerInterface $handler The handler
	 *
	 * @throws \Throwable
	 *
	 * @return ResponseInterface The response
	 */
	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if ($this->options['enable'] === false || !isset($this->options['init']['dsn'])) {
			return $handler->handle($request);
		}

		try {
			\Sentry\init($this->options['init']);

			return $handler->handle($request);
		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);

			throw $exception;
		}
	}
}
