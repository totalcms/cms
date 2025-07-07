<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Security\Encryption\Cipher;

final class SentryMiddleware implements MiddlewareInterface
{
	public const SALT = 's3ntryR0cks';

	/** @param array<string,mixed> $options The sentry options */
	public function __construct(private array $options)
	{
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if ($this->options['enable'] === true && isset($this->options['init']['dsn'])) {
			self::initSentry($this->options);
		}

		return $handler->handle($request);
	}

	/** @param array<string,mixed> $options The sentry options */
	public static function initSentry(array $options): void
	{
		$options['init']['dsn'] = Cipher::deobfuscate($options['init']['dsn'], self::SALT);

		try {
			\Sentry\init($options['init']);
		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);

			throw $exception;
		}
	}
}
