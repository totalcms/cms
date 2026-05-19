<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\PageReloadInjectorRenderer;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Support\Config;

/**
 * Tests the live-reload script injector. Dev Mode is the sole gate — the
 * script is injected for every visitor when Dev Mode is active, and not at
 * all when it's off.
 */
final class PageReloadInjectorRendererTest extends TestCase
{
	private DevModeManager&MockObject $devModeManager;
	private Config $config;
	private PageReloadInjectorRenderer $renderer;

	protected function setUp(): void
	{
		$this->devModeManager = $this->createMock(DevModeManager::class);
		$this->config         = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->api    = 'https://example.test';
		$this->renderer       = new PageReloadInjectorRenderer($this->devModeManager, $this->config);
	}

	public function testReturnsBodyUnchangedWhenDevModeOff(): void
	{
		$this->devModeManager->method('isDevModeActive')->willReturn(false);

		$body = '<html><body>hi</body></html>';
		$this->assertSame($body, $this->renderer->maybeInject($body, $this->request()));
	}

	public function testInjectsScriptWhenDevModeOn(): void
	{
		$this->devModeManager->method('isDevModeActive')->willReturn(true);

		$result = $this->renderer->maybeInject('<html><body>hi</body></html>', $this->request());

		$this->assertStringContainsString('<script', $result);
		$this->assertStringContainsString('EventSource', $result);
		$this->assertStringContainsString('https://example.test/livereload/events', $result);
		$this->assertStringContainsString('location.reload()', $result);
	}

	public function testInjectsBeforeClosingBodyTag(): void
	{
		$this->devModeManager->method('isDevModeActive')->willReturn(true);

		$result = $this->renderer->maybeInject('<html><body>hi</body></html>', $this->request());
		$this->assertMatchesRegularExpression('#<script[^>]*data-totalcms="builder-live-reload".*</body></html>$#s', $result);
	}

	public function testFallsBackToAppendingWhenNoBodyTagPresent(): void
	{
		$this->devModeManager->method('isDevModeActive')->willReturn(true);

		$result = $this->renderer->maybeInject('<div>fragment</div>', $this->request());
		$this->assertStringStartsWith('<div>fragment</div>', $result);
		$this->assertStringContainsString('<script', $result);
	}

	private function request(): ServerRequestInterface
	{
		return (new Psr17Factory())->createServerRequest('GET', '/about');
	}
}
