<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Service\AutomationGuard;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Support\Config;

final class AutomationGuardTest extends TestCase
{
	private function makeGuard(string $env): AutomationGuard
	{
		$config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->env = $env;
		$resolver    = new EnvironmentResolver($config, false);

		// ArrayObject is captured by handle, so the mock callbacks mutate one
		// shared store reliably across calls (a by-ref array does not).
		$store = new \ArrayObject();
		$cache = $this->createMock(CacheManager::class);
		$cache->method('getData')->willReturnCallback(fn (string $key): mixed => $store[$key] ?? null);
		$cache->method('storeData')->willReturnCallback(function (string $key, mixed $value) use ($store): bool {
			$store[$key] = $value;

			return true;
		});
		$cache->method('clearData')->willReturnCallback(function (string $key) use ($store): bool {
			unset($store[$key]);

			return true;
		});

		return new AutomationGuard($resolver, $cache);
	}

	public function testSignalsAutoDisableOnFifthProdFailure(): void
	{
		$guard = $this->makeGuard('prod');

		for ($i = 1; $i < 5; $i++) {
			$this->assertFalse($guard->recordFailure('daily'), "failure {$i} should not yet disable");
		}
		$this->assertTrue($guard->recordFailure('daily'), '5th failure should disable');
	}

	public function testNeverAutoDisablesInDevelopment(): void
	{
		$guard = $this->makeGuard('dev');

		for ($i = 0; $i < 10; $i++) {
			$this->assertFalse($guard->recordFailure('daily'));
		}
		$this->assertTrue($guard->shouldSurfaceErrors()); // dev surfaces errors loudly
	}

	public function testResetClearsTheCounter(): void
	{
		$guard = $this->makeGuard('prod');

		for ($i = 0; $i < 4; $i++) {
			$guard->recordFailure('daily');
		}
		$guard->reset('daily');

		// After reset, the next failure starts counting from 1 again (not disabled).
		$this->assertFalse($guard->recordFailure('daily'));
	}
}
