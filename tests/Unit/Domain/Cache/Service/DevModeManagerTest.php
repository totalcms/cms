<?php

namespace Tests\Unit\Domain\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\DevModeManager;

final class DevModeManagerTest extends TestCase
{
	private DevModeManager $manager;

	protected function setUp(): void
	{
		$this->manager = new DevModeManager();
		// Ensure clean state
		$this->manager->disableDevMode();
	}

	protected function tearDown(): void
	{
		// Clean up after each test
		$this->manager->disableDevMode();
	}

	public function testDevModeIsDisabledByDefault(): void
	{
		$this->assertFalse($this->manager->isDevModeActive());
	}

	public function testEnableDevModeActivatesDevMode(): void
	{
		$this->manager->enableDevMode();

		$this->assertTrue($this->manager->isDevModeActive());
	}

	public function testDisableDevModeDeactivatesDevMode(): void
	{
		$this->manager->enableDevMode();
		$this->assertTrue($this->manager->isDevModeActive());

		$this->manager->disableDevMode();
		$this->assertFalse($this->manager->isDevModeActive());
	}

	public function testGetRemainingTimeReturnsZeroWhenDisabled(): void
	{
		$this->assertSame(0, $this->manager->getRemainingTime());
	}

	public function testGetRemainingTimeReturnsPositiveValueWhenEnabled(): void
	{
		$this->manager->enableDevMode();

		$remaining = $this->manager->getRemainingTime();
		$this->assertGreaterThan(0, $remaining);
		$this->assertLessThanOrEqual(10800, $remaining); // 3 hours max
	}

	public function testGetDevModeStatusWhenDisabled(): void
	{
		$status = $this->manager->getDevModeStatus();

		$this->assertFalse($status['enabled']);
		$this->assertSame(0, $status['remaining_seconds']);
		$this->assertSame('0:00:00', $status['remaining_formatted']);
		$this->assertNull($status['expires_at']);
		$this->assertNull($status['started_at']);
	}

	public function testGetDevModeStatusWhenEnabled(): void
	{
		$this->manager->enableDevMode();

		$status = $this->manager->getDevModeStatus();

		$this->assertTrue($status['enabled']);
		$this->assertGreaterThan(0, $status['remaining_seconds']);
		$this->assertNotNull($status['expires_at']);
		$this->assertNotNull($status['started_at']);
		$this->assertIsString($status['remaining_formatted']);
	}

	public function testRemainingFormattedIsValidTimeFormat(): void
	{
		$this->manager->enableDevMode();

		$status = $this->manager->getDevModeStatus();

		// Should match format like "2:59:59" or "0:01:30"
		$this->assertMatchesRegularExpression('/^\d+:\d{2}:\d{2}$/', $status['remaining_formatted']);
	}

	public function testDisableDevModeHandlesNonExistentFile(): void
	{
		// This should not throw an exception
		$this->manager->disableDevMode();
		$this->manager->disableDevMode(); // Second call should also work

		$this->assertFalse($this->manager->isDevModeActive());
	}

	public function testEnableDevModeSetsCorrectExpiration(): void
	{
		$beforeEnable = time();
		$this->manager->enableDevMode();
		$afterEnable = time();

		$status = $this->manager->getDevModeStatus();

		// expires_at should be approximately 3 hours (10800 seconds) from now
		$expectedExpiration = $beforeEnable + 10800;
		$this->assertGreaterThanOrEqual($expectedExpiration, $status['expires_at']);
		$this->assertLessThanOrEqual($afterEnable + 10800, $status['expires_at']);
	}

	public function testEnableDevModeSetsStartedAt(): void
	{
		$beforeEnable = time();
		$this->manager->enableDevMode();
		$afterEnable = time();

		$status = $this->manager->getDevModeStatus();

		$this->assertGreaterThanOrEqual($beforeEnable, $status['started_at']);
		$this->assertLessThanOrEqual($afterEnable, $status['started_at']);
	}
}
