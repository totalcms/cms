<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Event\EventDispatcher;

/**
 * Basic integration tests for DevModeManager without mocking dependencies.
 */
final class DevModeBasicWorkflowTest extends TestCase
{
	private DevModeManager $devModeManager;
	private string $testDevModeFile;

	protected function setUp(): void
	{
		$this->devModeManager  = new DevModeManager(new EventDispatcher(new NullLogger()));
		$this->testDevModeFile = sys_get_temp_dir() . '/totalcms_devmode.json';
		$this->cleanupDevModeFile();
	}

	protected function tearDown(): void
	{
		$this->cleanupDevModeFile();
	}

	private function cleanupDevModeFile(): void
	{
		if (file_exists($this->testDevModeFile)) {
			unlink($this->testDevModeFile);
		}
	}

	public function testCompleteDevModeWorkflow(): void
	{
		// Step 1: Verify initial state (dev mode inactive)
		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertEquals(0, $this->devModeManager->getRemainingTime());

		$status = $this->devModeManager->getDevModeStatus();
		$this->assertFalse($status['enabled']);
		$this->assertEquals(0, $status['remaining_seconds']);
		$this->assertEquals('0:00:00', $status['remaining_formatted']);
		$this->assertNull($status['expires_at']);
		$this->assertNull($status['started_at']);

		// Step 2: Enable dev mode
		$startTime = time();
		$this->devModeManager->enableDevMode();
		$endTime = time();

		// Verify dev mode is active
		$this->assertTrue($this->devModeManager->isDevModeActive());

		$remaining = $this->devModeManager->getRemainingTime();
		$this->assertGreaterThan(10795, $remaining);
		$this->assertLessThan(10801, $remaining);

		$status = $this->devModeManager->getDevModeStatus();
		$this->assertTrue($status['enabled']);
		$this->assertGreaterThan(10795, $status['remaining_seconds']);
		$this->assertLessThan(10801, $status['remaining_seconds']);
		$this->assertMatchesRegularExpression('/^\d+:\d{2}:\d{2}$/', $status['remaining_formatted']);
		$this->assertGreaterThanOrEqual($startTime, $status['started_at']);
		$this->assertLessThanOrEqual($endTime, $status['started_at']);
		$this->assertEquals($status['started_at'] + 10800, $status['expires_at']);

		// Step 3: Disable dev mode
		$this->devModeManager->disableDevMode();

		// Verify dev mode is inactive
		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertEquals(0, $this->devModeManager->getRemainingTime());

		$status = $this->devModeManager->getDevModeStatus();
		$this->assertFalse($status['enabled']);
		$this->assertEquals(0, $status['remaining_seconds']);
		$this->assertEquals('0:00:00', $status['remaining_formatted']);
		$this->assertNull($status['expires_at']);
		$this->assertNull($status['started_at']);
	}

	public function testDevModeExpirationWorkflow(): void
	{
		// Create a dev mode state that's about to expire
		$almostExpiredData = [
			'enabled'    => true,
			'expires_at' => time() + 5, // 5 seconds from now
			'started_at' => time() - 10795, // Almost 3 hours ago
		];

		file_put_contents($this->testDevModeFile, json_encode($almostExpiredData));

		// Verify it's still active
		$this->assertTrue($this->devModeManager->isDevModeActive());

		$remaining = $this->devModeManager->getRemainingTime();
		$this->assertGreaterThan(0, $remaining);
		$this->assertLessThan(10, $remaining);

		// Wait for expiration (simulate by creating expired data)
		$expiredData = [
			'enabled'    => true,
			'expires_at' => time() - 1, // 1 second ago
			'started_at' => time() - 10801, // More than 3 hours ago
		];

		file_put_contents($this->testDevModeFile, json_encode($expiredData));

		// Verify it's now inactive due to expiration
		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertEquals(0, $this->devModeManager->getRemainingTime());
		$this->assertFileDoesNotExist($this->testDevModeFile); // Should be cleaned up
	}

	public function testDevModeFileCorruptionRecovery(): void
	{
		// Create a corrupted dev mode file
		file_put_contents($this->testDevModeFile, 'corrupted json content');

		// Verify system handles corruption gracefully
		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertEquals(0, $this->devModeManager->getRemainingTime());
		$this->assertFileDoesNotExist($this->testDevModeFile); // Should be cleaned up

		// Verify we can enable dev mode after corruption
		$this->devModeManager->enableDevMode();
		$this->assertTrue($this->devModeManager->isDevModeActive());
	}

	public function testMultipleDevModeToggling(): void
	{
		// Test rapid toggling of dev mode
		for ($i = 0; $i < 5; $i++) {
			// Enable
			$this->devModeManager->enableDevMode();
			$this->assertTrue($this->devModeManager->isDevModeActive());

			// Disable
			$this->devModeManager->disableDevMode();
			$this->assertFalse($this->devModeManager->isDevModeActive());
		}
	}

	public function testDevModeStatusConsistency(): void
	{
		// Enable dev mode
		$this->devModeManager->enableDevMode();

		// Get status multiple times and verify consistency
		$status1 = $this->devModeManager->getDevModeStatus();
		sleep(1); // Wait 1 second
		$status2 = $this->devModeManager->getDevModeStatus();

		// Basic properties should be the same
		$this->assertEquals($status1['enabled'], $status2['enabled']);
		$this->assertEquals($status1['started_at'], $status2['started_at']);
		$this->assertEquals($status1['expires_at'], $status2['expires_at']);

		// Remaining time should decrease
		$this->assertGreaterThan($status2['remaining_seconds'], $status1['remaining_seconds']);
	}

	public function testTimeFormattingAccuracy(): void
	{
		$this->devModeManager->enableDevMode();
		$status = $this->devModeManager->getDevModeStatus();

		// Parse the formatted time
		$parts = explode(':', (string)$status['remaining_formatted']);
		$this->assertCount(3, $parts);

		$hours   = (int)$parts[0];
		$minutes = (int)$parts[1];
		$seconds = (int)$parts[2];

		// Verify it's approximately 3 hours
		$totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;
		$this->assertGreaterThan(10795, $totalSeconds);
		$this->assertLessThan(10801, $totalSeconds);

		// Verify formatting is correct
		$this->assertGreaterThanOrEqual(0, $hours);
		$this->assertGreaterThanOrEqual(0, $minutes);
		$this->assertLessThan(60, $minutes);
		$this->assertGreaterThanOrEqual(0, $seconds);
		$this->assertLessThan(60, $seconds);
	}

	public function testDevModeFileStructure(): void
	{
		$this->devModeManager->enableDevMode();
		$this->assertFileExists($this->testDevModeFile);

		$content = file_get_contents($this->testDevModeFile);
		$this->assertNotFalse($content);

		$data = json_decode($content, true);
		$this->assertIsArray($data);
		$this->assertArrayHasKey('enabled', $data);
		$this->assertArrayHasKey('expires_at', $data);
		$this->assertArrayHasKey('started_at', $data);

		$this->assertTrue($data['enabled']);
		$this->assertIsInt($data['expires_at']);
		$this->assertIsInt($data['started_at']);
		$this->assertEquals($data['started_at'] + 10800, $data['expires_at']);

		// Verify JSON is properly formatted
		$this->assertJson($content);
	}

	public function testConcurrentDevModeAccess(): void
	{
		// Simulate concurrent access by creating multiple DevModeManager instances
		$eventDispatcher = new EventDispatcher(new NullLogger());
		$manager1        = new DevModeManager($eventDispatcher);
		$manager2        = new DevModeManager($eventDispatcher);

		// First manager enables dev mode
		$manager1->enableDevMode();

		// Second manager should see the same state
		$this->assertTrue($manager1->isDevModeActive());
		$this->assertTrue($manager2->isDevModeActive());

		$status1 = $manager1->getDevModeStatus();
		$status2 = $manager2->getDevModeStatus();

		$this->assertEquals($status1['enabled'], $status2['enabled']);
		$this->assertEquals($status1['expires_at'], $status2['expires_at']);
		$this->assertEquals($status1['started_at'], $status2['started_at']);

		// First manager disables dev mode
		$manager1->disableDevMode();

		// Both should see it as disabled
		$this->assertFalse($manager1->isDevModeActive());
		$this->assertFalse($manager2->isDevModeActive());
	}
}
