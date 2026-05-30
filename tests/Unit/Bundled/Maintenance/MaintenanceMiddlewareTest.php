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

	// --- attaching the middleware activates maintenance (no data block required) ---

	public function testNoConfigGatesWithDefaults(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', []),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
		$this->assertStringContainsString('This page is temporarily unavailable.', (string)$response->getBody());
	}

	public function testNonArrayConfigGatesWithDefaults(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => 'yes']),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
	}

	public function testBooleanConfigGatesWithDefaults(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => true]),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
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
			$this->page('about', ['maintenance' => ['retryAfterMinutes' => 30]]),
		);

		$this->assertNotNull($response);
		// retryAfter is configured in minutes; the header is emitted in seconds.
		$this->assertSame('1800', $response->getHeaderLine('Retry-After'));
	}

	public function testRetryAfterConvertsMinutesToSeconds(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfterMinutes' => 2]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('120', $response->getHeaderLine('Retry-After'));
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
			$this->page('about', ['maintenance' => ['retryAfterMinutes' => 0]]),
		);

		$this->assertNotNull($response);
		$this->assertSame('0', $response->getHeaderLine('Retry-After'));
	}

	public function testNonNumericRetryAfterFallsBackToDefault(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfterMinutes' => 'soon']]),
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

	public function testLoggedInAdminBypassesGate(): void
	{
		$middleware = new MaintenanceMiddleware(isAdmin: static fn (): bool => true);

		$this->assertNull($middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => 'Down.']]),
		));
	}

	public function testNonAdminVisitorDoesNotBypass(): void
	{
		// Logged-out visitors AND front-end members both resolve to false here.
		$middleware = new MaintenanceMiddleware(isAdmin: static fn (): bool => false);

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => 'Down.']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
	}

	public function testNoAdminCheckDoesNotBypass(): void
	{
		// Default construction (no isAdmin closure) — safe default is to gate.
		$response = $this->middleware->handle(
			$this->request(),
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
		$middleware = new MaintenanceMiddleware(defaultRetryAfterMinutes: 15);

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => []]),
		);

		$this->assertNotNull($response);
		// 15 minutes → 900 seconds.
		$this->assertSame('900', $response->getHeaderLine('Retry-After'));
	}

	public function testPageRetryAfterOverridesExtensionDefault(): void
	{
		$middleware = new MaintenanceMiddleware(defaultRetryAfterMinutes: 15);

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['retryAfterMinutes' => 1]]),
		);

		$this->assertNotNull($response);
		// Page override (1 minute) wins over the extension default → 60 seconds.
		$this->assertSame('60', $response->getHeaderLine('Retry-After'));
	}

	// --- heading ---

	public function testDefaultHeadingRendered(): void
	{
		$response = $this->middleware->handle($this->request(), $this->page('about', []));

		$this->assertNotNull($response);
		$this->assertStringContainsString('<h1>Temporarily Unavailable</h1>', (string)$response->getBody());
	}

	public function testExtensionDefaultHeadingUsed(): void
	{
		$middleware = new MaintenanceMiddleware(defaultHeading: 'Down for Maintenance');

		$response = $middleware->handle($this->request(), $this->page('about', []));

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('<h1>Down for Maintenance</h1>', $body);
		$this->assertStringContainsString('<title>Down for Maintenance</title>', $body);
	}

	public function testPageHeadingOverridesDefault(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['heading' => 'Back at noon']]),
		);

		$this->assertNotNull($response);
		$this->assertStringContainsString('<h1>Back at noon</h1>', (string)$response->getBody());
	}

	public function testHeadingIsEscaped(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['heading' => '<b>oops</b>']]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringNotContainsString('<b>oops</b>', $body);
		$this->assertStringContainsString('&lt;b&gt;', $body);
	}

	// --- markdown message ---

	public function testMessageRendersMarkdownLink(): void
	{
		$response = $this->middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['message' => 'See [status](https://status.example.com).']]),
		);

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringContainsString('href="https://status.example.com"', $body);
		$this->assertStringContainsString('status</a>', $body);
	}

	// --- custom builder template ---

	public function testCustomTemplateRendererIsUsed(): void
	{
		$middleware = new MaintenanceMiddleware(
			template: 'maintenance',
			templateRenderer: static fn (string $name, array $vars): string => "RENDERED:{$name}:{$vars['heading']}",
		);

		$response = $middleware->handle(
			$this->request(),
			$this->page('about', ['maintenance' => ['heading' => 'Hi']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
		$this->assertSame('RENDERED:maintenance:Hi', (string)$response->getBody());
	}

	public function testCustomTemplateReceivesRenderedMessageHtml(): void
	{
		$captured = [];
		$middleware = new MaintenanceMiddleware(
			template: 'maintenance',
			templateRenderer: function (string $path, array $vars) use (&$captured): string {
				$captured = $vars;

				return 'ok';
			},
		);

		$middleware->handle($this->request(), $this->page('about', ['maintenance' => ['message' => '**bold**']]));

		$this->assertStringContainsString('<strong>bold</strong>', $captured['message']);
		$this->assertSame('**bold**', $captured['messageText']);
	}

	public function testFallsBackToDefaultWhenTemplateRendererThrows(): void
	{
		$middleware = new MaintenanceMiddleware(
			template: 'maintenance',
			templateRenderer: static function (string $path, array $vars): string {
				throw new \RuntimeException('boom');
			},
		);

		$response = $middleware->handle($this->request(), $this->page('about', []));

		$this->assertNotNull($response);
		$this->assertSame(503, $response->getStatusCode());
		$this->assertStringContainsString('Temporarily Unavailable', (string)$response->getBody());
	}

	public function testTemplateRendererIgnoredWhenPathBlank(): void
	{
		$middleware = new MaintenanceMiddleware(
			template: '',
			templateRenderer: static fn (string $path, array $vars): string => 'SHOULD NOT BE USED',
		);

		$response = $middleware->handle($this->request(), $this->page('about', []));

		$this->assertNotNull($response);
		$body = (string)$response->getBody();
		$this->assertStringNotContainsString('SHOULD NOT BE USED', $body);
		$this->assertStringContainsString('Temporarily Unavailable', $body);
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
