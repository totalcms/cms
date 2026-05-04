<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\PageReloadInjectorRenderer;
use TotalCMS\Support\Config;

/**
 * Tests the live-reload script injector. Covers the gating rules
 * (admin session, setting toggle) and the script's resolved endpoint URL.
 */
final class PageReloadInjectorRendererTest extends TestCase
{
	private AccessManager&MockObject $accessManager;
	private BuilderConfigService&MockObject $builderConfig;
	private Config $config;
	private PageReloadInjectorRenderer $renderer;

	protected function setUp(): void
	{
		$this->accessManager = $this->createMock(AccessManager::class);
		$this->builderConfig = $this->createMock(BuilderConfigService::class);
		$this->config        = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->api   = 'https://example.test';
		$this->renderer      = new PageReloadInjectorRenderer($this->accessManager, $this->builderConfig, $this->config);
	}

	public function testReturnsBodyUnchangedWhenNotLoggedIn(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(false);
		$this->builderConfig->method('isLiveReloadEnabled')->willReturn(true);

		$body = '<html><body>hi</body></html>';
		$this->assertSame($body, $this->renderer->maybeInject($body, $this->request()));
	}

	public function testReturnsBodyUnchangedWhenSettingDisabled(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->builderConfig->method('isLiveReloadEnabled')->willReturn(false);

		$body = '<html><body>hi</body></html>';
		$this->assertSame($body, $this->renderer->maybeInject($body, $this->request()));
	}

	public function testInjectsScriptWhenAdminAndEnabled(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->builderConfig->method('isLiveReloadEnabled')->willReturn(true);

		$result = $this->renderer->maybeInject('<html><body>hi</body></html>', $this->request());

		$this->assertStringContainsString('<script', $result);
		$this->assertStringContainsString('EventSource', $result);
		$this->assertStringContainsString('https://example.test/admin/builder/events', $result);
		$this->assertStringContainsString('location.reload()', $result);
	}

	public function testInjectsBeforeClosingBodyTag(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->builderConfig->method('isLiveReloadEnabled')->willReturn(true);

		$result = $this->renderer->maybeInject('<html><body>hi</body></html>', $this->request());
		$this->assertMatchesRegularExpression('#<script[^>]*data-totalcms="builder-live-reload".*</body></html>$#s', $result);
	}

	public function testFallsBackToAppendingWhenNoBodyTagPresent(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->builderConfig->method('isLiveReloadEnabled')->willReturn(true);

		$result = $this->renderer->maybeInject('<div>fragment</div>', $this->request());
		$this->assertStringStartsWith('<div>fragment</div>', $result);
		$this->assertStringContainsString('<script', $result);
	}

	private function request(): ServerRequestInterface
	{
		return (new Psr17Factory())->createServerRequest('GET', '/about');
	}
}
