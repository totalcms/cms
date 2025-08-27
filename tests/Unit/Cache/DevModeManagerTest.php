<?php

declare(strict_types=1);

namespace TotalCMS\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\DevModeManager;

/**
 * Test DevModeManager service.
 */
final class DevModeManagerTest extends TestCase
{
	private DevModeManager $devModeManager;
	private string $testFile;

	protected function setUp(): void
	{
		$this->devModeManager = new DevModeManager();
		$this->testFile       = sys_get_temp_dir() . '/totalcms_devmode.json';

		// Clean up any existing state
		if (file_exists($this->testFile)) {
			unlink($this->testFile);
		}
	}

	protected function tearDown(): void
	{
		// Clean up after each test
		if (file_exists($this->testFile)) {
			unlink($this->testFile);
		}
	}

	public function testDevModeInitiallyInactive(): void
	{
		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertSame(0, $this->devModeManager->getRemainingTime());
	}

	public function testEnableDevMode(): void
	{
		$this->devModeManager->enableDevMode();

		$this->assertTrue($this->devModeManager->isDevModeActive());
		$this->assertGreaterThan(10000, $this->devModeManager->getRemainingTime()); // Should be close to 3 hours
		$this->assertLessThanOrEqual(10800, $this->devModeManager->getRemainingTime()); // 3 hours = 10800 seconds
	}

	public function testDisableDevMode(): void
	{
		$this->devModeManager->enableDevMode();
		$this->assertTrue($this->devModeManager->isDevModeActive());

		$this->devModeManager->disableDevMode();

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertSame(0, $this->devModeManager->getRemainingTime());
		$this->assertFileDoesNotExist($this->testFile);
	}

	public function testGetDevModeStatusWhenDisabled(): void
	{
		$status = $this->devModeManager->getDevModeStatus();

		$this->assertFalse($status['enabled']);
		$this->assertSame(0, $status['remaining_seconds']);
		$this->assertSame('0:00:00', $status['remaining_formatted']);
		$this->assertNull($status['expires_at']);
		$this->assertNull($status['started_at']);
	}

	public function testGetDevModeStatusWhenEnabled(): void
	{
		$this->devModeManager->enableDevMode();
		$status = $this->devModeManager->getDevModeStatus();

		$this->assertTrue($status['enabled']);
		$this->assertGreaterThan(0, $status['remaining_seconds']);
		$this->assertNotNull($status['expires_at']);
		$this->assertNotNull($status['started_at']);
		$this->assertMatchesRegularExpression('/^\d+:\d{2}:\d{2}$/', $status['remaining_formatted']);
	}

	public function testTimeFormatting(): void
	{
		$this->devModeManager->enableDevMode();
		$status = $this->devModeManager->getDevModeStatus();

		// Should be close to 3:00:00 when just enabled
		$this->assertMatchesRegularExpression('/^[23]:\d{2}:\d{2}$/', $status['remaining_formatted']);
	}

	public function testExpiredDevModeIsAutomaticallyDisabled(): void
	{
		// Create an expired dev mode file
		$expiredData = [
			'enabled'    => true,
			'expires_at' => time() - 100, // Expired 100 seconds ago
			'started_at' => time() - 10900, // Started over 3 hours ago
		];

		file_put_contents($this->testFile, json_encode($expiredData, JSON_PRETTY_PRINT));

		// Should be automatically detected as inactive
		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertSame(0, $this->devModeManager->getRemainingTime());

		// File should be cleaned up
		$this->assertFileDoesNotExist($this->testFile);
	}

	public function testCorruptedFileIsHandledGracefully(): void
	{
		// Create a corrupted file
		file_put_contents($this->testFile, 'invalid json content');

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertSame(0, $this->devModeManager->getRemainingTime());

		// File should be cleaned up
		$this->assertFileDoesNotExist($this->testFile);
	}

	public function testMissingFileIsHandledGracefully(): void
	{
		// Ensure file doesn't exist
		if (file_exists($this->testFile)) {
			unlink($this->testFile);
		}

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertSame(0, $this->devModeManager->getRemainingTime());

		$status = $this->devModeManager->getDevModeStatus();
		$this->assertFalse($status['enabled']);
	}

	public function testIncompleteFileDataIsHandledGracefully(): void
	{
		// Create file with missing required fields
		$incompleteData = [
			'enabled' => true,
			// Missing expires_at and started_at
		];

		file_put_contents($this->testFile, json_encode($incompleteData, JSON_PRETTY_PRINT));

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertSame(0, $this->devModeManager->getRemainingTime());
	}

	public function testReenablingDevModeResetsTimer(): void
	{
		// Enable dev mode
		$this->devModeManager->enableDevMode();
		$firstRemainingTime = $this->devModeManager->getRemainingTime();

		// Wait a tiny bit
		usleep(10000); // 10ms

		// Re-enable dev mode
		$this->devModeManager->enableDevMode();
		$secondRemainingTime = $this->devModeManager->getRemainingTime();

		// Second time should be equal or greater (timer reset)
		$this->assertGreaterThanOrEqual($firstRemainingTime - 1, $secondRemainingTime);
	}
}
