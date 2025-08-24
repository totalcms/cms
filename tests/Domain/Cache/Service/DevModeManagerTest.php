<?php

declare(strict_types = 1);

namespace Tests\Domain\Cache\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\Service\DevModeManager;

/**
 * @covers \TotalCMS\Domain\Cache\Service\DevModeManager
 */
final class DevModeManagerTest extends TestCase
{
	private DevModeManager $devModeManager;
	private string $testDevModeFile;

	protected function setUp(): void
	{
		$this->devModeManager  = new DevModeManager();
		$this->testDevModeFile = sys_get_temp_dir() . '/totalcms_devmode.json';

		// Clean up any existing dev mode file
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

	public function testIsDevModeActiveReturnsFalseWhenNoFileExists(): void
	{
		$this->assertFalse($this->devModeManager->isDevModeActive());
	}

	public function testEnableDevModeCreatesFileWithCorrectStructure(): void
	{
		$startTime = time();
		$this->devModeManager->enableDevMode();
		$endTime = time();

		$this->assertFileExists($this->testDevModeFile);

		$content = file_get_contents($this->testDevModeFile);
		$this->assertNotFalse($content);

		$data = json_decode($content, true);
		$this->assertIsArray($data);
		$this->assertArrayHasKey('enabled', $data);
		$this->assertArrayHasKey('expires_at', $data);
		$this->assertArrayHasKey('started_at', $data);

		$this->assertTrue($data['enabled']);
		$this->assertGreaterThanOrEqual($startTime, $data['started_at']);
		$this->assertLessThanOrEqual($endTime, $data['started_at']);
		$this->assertEquals($data['started_at'] + 10800, $data['expires_at']); // 3 hours
	}

	public function testIsDevModeActiveReturnsTrueWhenFileExistsAndNotExpired(): void
	{
		$this->devModeManager->enableDevMode();
		$this->assertTrue($this->devModeManager->isDevModeActive());
	}

	public function testIsDevModeActiveReturnsFalseWhenFileExpired(): void
	{
		// Create an expired dev mode file
		$expiredData = [
			'enabled'    => true,
			'expires_at' => time() - 3600, // 1 hour ago
			'started_at' => time() - 7200,   // 2 hours ago
		];

		file_put_contents($this->testDevModeFile, json_encode($expiredData));

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertFileDoesNotExist($this->testDevModeFile); // Should be cleaned up
	}

	public function testIsDevModeActiveReturnsFalseWithCorruptedFile(): void
	{
		file_put_contents($this->testDevModeFile, 'invalid json content');

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertFileDoesNotExist($this->testDevModeFile); // Should be cleaned up
	}

	public function testIsDevModeActiveReturnsFalseWithIncompleteData(): void
	{
		$incompleteData = ['enabled' => true]; // missing expires_at
		file_put_contents($this->testDevModeFile, json_encode($incompleteData));

		$this->assertFalse($this->devModeManager->isDevModeActive());
	}

	public function testDisableDevModeRemovesFile(): void
	{
		$this->devModeManager->enableDevMode();
		$this->assertFileExists($this->testDevModeFile);

		$this->devModeManager->disableDevMode();
		$this->assertFileDoesNotExist($this->testDevModeFile);
	}

	public function testDisableDevModeWithNonExistentFile(): void
	{
		// Should not throw an error
		$this->devModeManager->disableDevMode();
		$this->assertFileDoesNotExist($this->testDevModeFile);
	}

	public function testGetRemainingTimeReturnsZeroWhenInactive(): void
	{
		$this->assertEquals(0, $this->devModeManager->getRemainingTime());
	}

	public function testGetRemainingTimeReturnsCorrectValueWhenActive(): void
	{
		$this->devModeManager->enableDevMode();
		$remaining = $this->devModeManager->getRemainingTime();

		// Should be close to 3 hours (10800 seconds), allowing for small timing differences
		$this->assertGreaterThan(10795, $remaining);
		$this->assertLessThan(10801, $remaining);
	}

	public function testGetRemainingTimeReturnsZeroWhenExpired(): void
	{
		$expiredData = [
			'enabled'    => true,
			'expires_at' => time() - 1,
			'started_at' => time() - 3601,
		];

		file_put_contents($this->testDevModeFile, json_encode($expiredData));

		$this->assertEquals(0, $this->devModeManager->getRemainingTime());
	}

	public function testGetDevModeStatusWhenInactive(): void
	{
		$status = $this->devModeManager->getDevModeStatus();

		$this->assertIsArray($status);
		$this->assertFalse($status['enabled']);
		$this->assertEquals(0, $status['remaining_seconds']);
		$this->assertEquals('0:00:00', $status['remaining_formatted']);
		$this->assertNull($status['expires_at']);
		$this->assertNull($status['started_at']);
	}

	public function testGetDevModeStatusWhenActive(): void
	{
		$startTime = time();
		$this->devModeManager->enableDevMode();
		$endTime = time();

		$status = $this->devModeManager->getDevModeStatus();

		$this->assertIsArray($status);
		$this->assertTrue($status['enabled']);
		$this->assertGreaterThan(10795, $status['remaining_seconds']);
		$this->assertLessThan(10801, $status['remaining_seconds']);
		$this->assertStringMatchesFormat('%d:%d:%d', $status['remaining_formatted']);
		$this->assertGreaterThanOrEqual($startTime, $status['started_at']);
		$this->assertLessThanOrEqual($endTime, $status['started_at']);
		$this->assertEquals($status['started_at'] + 10800, $status['expires_at']);
	}

	public function testFormatTimeCorrectly(): void
	{
		$this->devModeManager->enableDevMode();
		$status = $this->devModeManager->getDevModeStatus();

		// Should format as H:MM:SS
		$this->assertMatchesRegularExpression('/^\d+:\d{2}:\d{2}$/', $status['remaining_formatted']);

		// For 3 hours, should start with "2:5" or "3:0" (approximately)
		$this->assertStringStartsWithOneOf(['2:5', '2:6', '3:0'], $status['remaining_formatted']);
	}

	private function assertStringStartsWithOneOf(array $prefixes, string $actual): void
	{
		$matches = false;
		foreach ($prefixes as $prefix) {
			if (str_starts_with($actual, (string) $prefix)) {
				$matches = true;
				break;
			}
		}

		$this->assertTrue($matches, "String '$actual' does not start with any of: " . implode(', ', $prefixes));
	}

	public function testJsonExceptionHandling(): void
	{
		// Create a file that will cause JSON exception
		file_put_contents($this->testDevModeFile, "\x00\x01\x02"); // Invalid UTF-8

		$this->assertFalse($this->devModeManager->isDevModeActive());
		$this->assertEquals(0, $this->devModeManager->getRemainingTime());

		$status = $this->devModeManager->getDevModeStatus();
		$this->assertFalse($status['enabled']);
	}

	public function testFileReadErrorHandling(): void
	{
		// Create a directory with the same name as the file to cause read error
		mkdir($this->testDevModeFile);

		// Set custom error handler to suppress expected file_get_contents warning
		set_error_handler(function ($severity, $message, $file, $line): bool {
			// Only suppress warnings from file_get_contents about directory read errors
			if ($severity === E_NOTICE && str_contains($message, 'file_get_contents') && str_contains($message, 'Is a directory')) {
				return true; // Suppress this specific notice
			}
			// Let other errors through
			return false;
		});

		try {
			$this->assertFalse($this->devModeManager->isDevModeActive());
		} finally {
			// Restore original error handler and clean up
			restore_error_handler();
			rmdir($this->testDevModeFile);
		}
	}
}
