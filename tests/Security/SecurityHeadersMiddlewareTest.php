<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\Security\SecurityHeadersMiddleware;

final class SecurityHeadersMiddlewareTest extends TestCase
{
	/** @var array<string,string> */
	private array $headers = [];

	public function testEmitsAdminSecurityHeaders(): void
	{
		$this->runMiddleware();

		expect($this->headers)->toHaveKeys([
			'Content-Security-Policy',
			'X-Frame-Options',
			'X-Content-Type-Options',
			'Referrer-Policy',
		]);
		expect($this->headers['X-Content-Type-Options'])->toBe('nosniff');
		expect($this->headers['Referrer-Policy'])->toBe('strict-origin-when-cross-origin');
	}

	public function testAllowsSameOriginFramingForFileLinksDialog(): void
	{
		// Depot/File fields embed /admin/filelinks in a same-origin iframe —
		// the policy must be 'self'/SAMEORIGIN, never 'none'/DENY.
		$this->runMiddleware();

		expect($this->headers['Content-Security-Policy'])->toBe("frame-ancestors 'self'");
		expect($this->headers['X-Frame-Options'])->toBe('SAMEORIGIN');
	}

	private function runMiddleware(): void
	{
		$this->headers = [];

		$response = $this->createMock(ResponseInterface::class);
		$response->method('withHeader')->willReturnCallback(
			function (string $name, string $value) use ($response): ResponseInterface {
				$this->headers[$name] = $value;

				return $response;
			},
		);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($response);

		(new SecurityHeadersMiddleware())->process(
			$this->createMock(ServerRequestInterface::class),
			$handler,
		);
	}
}
