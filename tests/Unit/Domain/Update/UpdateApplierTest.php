<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Update\Service\MaintenanceMode;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Factory\LoggerFactory;

/**
 * Tests for UpdateApplier.
 *
 * These tests verify the applier's behavior using mocks since the actual
 * apply/rollback methods manipulate the real app root directory and are
 * better suited for integration/manual testing. Here we focus on error
 * conditions and the rollback lookup logic.
 */
final class UpdateApplierTest extends TestCase
{
	private string $tmpDir;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-applier-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);
		mkdir($this->tmpDir . '/logs', 0755, true);
	}

	protected function tearDown(): void
	{
		$this->deleteDir($this->tmpDir);
	}

	private function createApplier(): UpdateApplier
	{
		$maintenanceMode = $this->createMock(MaintenanceMode::class);
		$cacheManager    = $this->createMock(CacheManager::class);
		$loggerFactory   = new LoggerFactory(['path' => $this->tmpDir . '/logs', 'level' => \Monolog\Level::Debug]);

		return new UpdateApplier($maintenanceMode, $cacheManager, $loggerFactory);
	}

	public function testApplyFailsWithInvalidZip(): void
	{
		$applier = $this->createApplier();

		$badZip = $this->tmpDir . '/bad.zip';
		file_put_contents($badZip, 'not a zip');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to open update zip');

		$applier->apply($badZip, '3.3.0');
	}

	public function testApplyFailsWithMissingZip(): void
	{
		$applier = $this->createApplier();

		$this->expectException(\RuntimeException::class);

		$applier->apply($this->tmpDir . '/nonexistent.zip', '3.3.0');
	}

	public function testRollbackFailsWithNoBackup(): void
	{
		$applier = $this->createApplier();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No backup found');

		$applier->rollback();
	}

	private function deleteDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}

		rmdir($dir);
	}
}
