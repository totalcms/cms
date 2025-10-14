<?php

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Security\Encryption\Cipher;

class SentryMiddleware implements MiddlewareInterface
{
	public const SALT             = 's3ntryR0cks';
	private const DEFAULT_OPTIONS = [
		'dsn' => 'p16xTYgwpMx9Z9UBsuOuqV7N7v9NgKpf_3RN7XSvTAiFs3OQXJcSlY5n4IGK-4dbKnAhOvY59eZujBuqmIJN7kAlximb86OwSyrMs9lzODhTfr6jMGXQp2Vs1fLlHRY',
		// Specify a fixed sample rate
		'traces_sample_rate' => 1.0,
		// Set a sampling rate for profiling - this is relative to traces_sample_rate
		'profiles_sample_rate' => 1.0,
		'ignore_exceptions'    => [
			HttpNotFoundException::class,
			HttpMethodNotAllowedException::class,
		],
	];

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(private readonly bool $enable = true)
	{
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if ($this->enable) {
			self::initSentry();
		}

		return $handler->handle($request);
	}

	public static function initSentry(): void
	{
		$options        = self::DEFAULT_OPTIONS;
		$options['dsn'] = Cipher::deobfuscate($options['dsn'], self::SALT);

		try {
			\Sentry\init($options);
		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);

			throw $exception;
		}
	}
}
