<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\Maintenance;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Bundled\Maintenance\MaintenanceMiddleware;
use TotalCMS\Domain\Builder\Data\PageData;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/maintenance/MaintenanceMiddleware.php';

final class MaintenanceMiddlewareTest extends TestCase
{
	private MaintenanceMiddleware $middleware;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->middleware = new MaintenanceMiddleware();
		$this->psr17      = new Psr17Factory();
	}

	// --- no-op cases ---

	public function testNoConfigIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('about', []),
		));
	}

	public function testNonArrayConfigIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => 'yes']),
		));
	}

	public function testBooleanConfigIsNoOp(): void
	{
		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => true]),
		));
	}

	// --- 503 response ---

	public function testMaintenanceReturns503(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => 'Down for updates.']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
	}

	public function testCustomMessageIsRendered(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => 'Back at 5pm EST.']]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Back at 5pm EST.', $body);
	}

	public function testDefaultMessageWhenEmpty(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => []]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('This page is temporarily unavailable.', $body);
	}

	public function testRetryAfterHeader(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfter' => 1800]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('1800', $response->getHeaderLine('Retry-After'));
	}

	public function testDefaultRetryAfterIsOneHour(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => []]),
		);

		$this->assertNotNull($response);
		$this->assertSame('3600', $response->getHeaderLine('Retry-After'));
	}

	public function testRetryAfterZeroIsAllowed(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfter' => 0]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('0', $response->getHeaderLine('Retry-After'));
	}

	public function testNonNumericRetryAfterFallsBackToDefault(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfter' => 'soon']]),
		);

		$this->assertNotNull($response);
		$this->assertSame('3600', $response->getHeaderLine('Retry-After'));
	}

	public function testHtmlMessageIsEscaped(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => '<script>alert("xss")</script>']]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringNotContainsString('<script>', $body);
		$this->assertStringContainsString('&lt;script&gt;', $body);
	}

	// --- admin bypass ---

	public function testAdminBypassesMaintenanceGate(): void
	{
		$session = new class {
			public function get(string $key): mixed
			{
				return $key === 'AUTH_USER' ? 'admin@example.com' : null;
			}
		};

		$request = $this->request()->withAttribute('session', $session);

		$this->assertNull($this->middleware->handle(
			$request,
			$this->page('about', ['maintenance' => ['message' => 'Down.']]),
		));
	}

	public function testNonAdminSessionDoesNotBypass(): void
	{
		$session = new class {
			public function get(string $key): mixed
			{
				return null;
			}
		};

		$request = $this->request()->withAttribute('session', $session);

		$response = $this->middleware->handle(
			$request,
			$this->page('about', ['maintenance' => ['message' => 'Down.']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
	}

	// --- extension-level defaults ---

	public function testExtensionDefaultMessageUsedWhenPageOmitsIt(): void
	{
		$middleware = new MaintenanceMiddleware(defaultMessage: 'Site-wide default.');

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => []]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Site-wide default.', $body);
	}

	public function testPageMessageOverridesExtensionDefault(): void
	{
		$middleware = new MaintenanceMiddleware(defaultMessage: 'Site-wide default.');

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => 'Page-specific message.']]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('Page-specific message.', $body);
		$this->assertStringNotContainsString('Site-wide default.', $body);
	}

	public function testExtensionDefaultRetryAfterUsedWhenPageOmitsIt(): void
	{
		$middleware = new MaintenanceMiddleware(defaultRetryAfter: 900);

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => []]),
		);

		$this->assertNotNull($response);
		$this->assertSame('900', $response->getHeaderLine('Retry-After'));
	}

	public function testPageRetryAfterOverridesExtensionDefault(): void
	{
		$middleware = new MaintenanceMiddleware(defaultRetryAfter: 900);

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfter' => 60]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('60', $response->getHeaderLine('Retry-After'));
	}

	// --- helpers ---

	/** @param array<string,mixed> $data */
	private function page(string $id, array $data): PageData
	{
		return new PageData(['id' => $id, 'data' => $data]);
	}

	private function request(): \Psr\Http\Message\ServerRequestInterface
	{
		return $this->psr17->createServerRequest('GET', '/about');
	}
}
