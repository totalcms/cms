<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Utils\Cipher;

final class SentryMiddleware implements MiddlewareInterface
{
	const SALT = "s3ntryR0cks";

	/** @param array<string,mixed> $options The sentry options */
	public function __construct(private array $options) {}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if ($this->options['enable'] === false || !isset($this->options['init']['dsn'])) {
			return $handler->handle($request);
		}

		$this->options['init']['dsn'] = Cipher::deobfuscate($this->options['init']['dsn'], self::SALT);

		try {
			\Sentry\init($this->options['init']);

			return $handler->handle($request);
		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);

			throw $exception;
		}
	}
}
