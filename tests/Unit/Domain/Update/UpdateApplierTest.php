<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Update\Service\MaintenanceMode;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Tests for UpdateApplier.
 *
 * Error-condition tests use the real app root (they fault before touching any
 * files). The filesystem-behaviour tests inject a temp app root so they can
 * assert the in-place swap installs new code while NEVER moving user content,
 * and rolls back cleanly on failure.
 */
final class UpdateApplierTest extends TestCase
{
	private string $tmpDir;
	private string $appRoot;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-applier-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);
		mkdir($this->tmpDir . '/logs', 0755, true);
		$this->appRoot = $this->tmpDir . '/app';
	}

	protected function tearDown(): void
	{
		$this->deleteDir($this->tmpDir);
	}

	// ── Error conditions (real app root; faults before any file work) ────────

	public function testApplyFailsWithInvalidZip(): void
	{
		$badZip = $this->tmpDir . '/bad.zip';
		file_put_contents($badZip, 'not a zip');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to open update zip');

		$this->createApplier()->apply($badZip, '3.3.0');
	}

	public function testApplyFailsWithMissingZip(): void
	{
		$this->expectException(\RuntimeException::class);

		$this->createApplier()->apply($this->tmpDir . '/nonexistent.zip', '3.3.0');
	}

	public function testRollbackFailsWithNoBackup(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No backup found');

		$this->createApplier()->rollback();
	}

	// ── Filesystem behaviour (injected temp app root) ────────────────────────

	public function testInstallsNewCodeAndPreservesUserData(): void
	{
		$this->seedInstall();
		$zip = $this->makeZip('9.9.9');

		$this->createApplier($this->appRoot)->apply($zip, '9.9.9');

		// New code installed; the old src/ was fully replaced.
		$this->assertFileExists($this->appRoot . '/src/New.php');
		$this->assertFileDoesNotExist($this->appRoot . '/src/Old.php');
		$this->assertFileExists($this->appRoot . '/composer.json');
		$this->assertStringContainsString('new index', (string)file_get_contents($this->appRoot . '/public/index.php'));

		// User content + config + logs preserved untouched.
		$this->assertSame('{"title":"keep me"}', (string)file_get_contents($this->appRoot . '/tcms-data/blog/post-1.json'));
		$this->assertFileExists($this->appRoot . '/.env');
		$this->assertFileExists($this->appRoot . '/tcms.php');
		$this->assertFileExists($this->appRoot . '/logs/updates.log');

		// Backup cleaned up + downloaded zip removed.
		$this->assertSame([], glob($this->appRoot . '.backup-*') ?: []);
		$this->assertFileDoesNotExist($zip);
	}

	public function testUnwrapsASingleTopLevelWrapperDirectory(): void
	{
		$this->seedInstall();
		$zip = $this->makeZip('9.9.8', wrapped: true);

		$this->createApplier($this->appRoot)->apply($zip, '9.9.8');

		$this->assertFileExists($this->appRoot . '/src/New.php');
		$this->assertDirectoryDoesNotExist($this->appRoot . '/totalcms-9.9.8');
	}

	public function testKeepsUsersTcmsDataEvenIfTheArchiveShipsIt(): void
	{
		$this->seedInstall();
		$zip = $this->makeZip('9.9.6', includeTcmsData: true);

		$this->createApplier($this->appRoot)->apply($zip, '9.9.6');

		$this->assertSame('{"title":"keep me"}', (string)file_get_contents($this->appRoot . '/tcms-data/blog/post-1.json'));
	}

	public function testRollsBackAndPreservesDataWhenFinalizeFailsAfterSwap(): void
	{
		$this->seedInstall();

		// disable() throws on the FIRST (post-swap) call and succeeds on the
		// catch-path call — a failure that occurs after the swap.
		$maintenance = $this->createMock(MaintenanceMode::class);
		$calls       = 0;
		$maintenance->method('disable')->willReturnCallback(function () use (&$calls): void {
			if ($calls++ === 0) {
				throw new \RuntimeException('boom after swap');
			}
		});

		$zip = $this->makeZip('9.9.7');

		try {
			$this->createApplier($this->appRoot, $maintenance)->apply($zip, '9.9.7');
			$this->fail('expected the update to throw');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('failed', $e->getMessage());
		}

		// Old code restored, new code reverted, user data intact.
		$this->assertFileExists($this->appRoot . '/src/Old.php');
		$this->assertFileDoesNotExist($this->appRoot . '/src/New.php');
		$this->assertFileExists($this->appRoot . '/tcms-data/blog/post-1.json');
		$this->assertFileExists($this->appRoot . '/.env');
	}

	// ── backup retention ─────────────────────────────────────────────────────

	/**
	 * The previous version is kept INSIDE the data directory, never as the
	 * `{appRoot}.backup-*` sibling it is built as. That sibling sits in the
	 * document root in the recommended layout, so retaining it there would
	 * leave the old release's public/, src/ and vendor/ executable on the web
	 * — including whatever the update just patched.
	 */
	public function testKeepsThePreviousVersionInTheDataDirectory(): void
	{
		$this->seedInstall();
		$this->createApplier($this->appRoot)->apply($this->makeZip('3.9.9'), '3.9.9');

		$retained = $this->retained();
		$this->assertNotNull($retained, 'previous version should be retained');
		$this->assertFileExists($retained . '/src/Old.php');
		$this->assertStringContainsString('old index', (string)file_get_contents($retained . '/public/index.php'));

		// Nothing left beside the app root, where the web server can reach it.
		$this->assertSame([], glob($this->appRoot . '.backup-*') ?: []);
	}

	public function testDiscardsThePreviousVersionWhenNotAskedToKeepIt(): void
	{
		$this->seedInstall();
		$this->createApplier($this->appRoot)->apply($this->makeZip('3.9.9'), '3.9.9', keepBackup: false);

		$this->assertNull($this->retained());
		$this->assertSame([], glob($this->appRoot . '.backup-*') ?: []);
		$this->assertFileExists($this->appRoot . '/src/New.php');
	}

	public function testKeepsOnlyOnePreviousVersion(): void
	{
		$this->seedInstall();
		$this->createApplier($this->appRoot)->apply($this->makeZip('3.9.8'), '3.9.8');
		$this->createApplier($this->appRoot)->apply($this->makeZip('3.9.9'), '3.9.9');

		$dirs = glob($this->tmpDir . '/tcms-data/.system/backups/*', GLOB_ONLYDIR) ?: [];
		$this->assertCount(1, $dirs, 'only the most recent previous version is kept');
	}

	/**
	 * When the retained copy cannot be created, the backup is discarded rather
	 * than left beside the app root. A backup silently sitting in the web root
	 * is the outcome this feature exists to prevent.
	 */
	public function testDiscardsRatherThanLeavingTheBackupInTheWebRootWhenRetentionFails(): void
	{
		$this->seedInstall();
		// A FILE where the data directory should be: mkdir of .system/backups
		// cannot succeed.
		$blocked = $this->tmpDir . '/blocked-datadir';
		file_put_contents($blocked, 'not a directory');

		$this->createApplier($this->appRoot, datadir: $blocked)->apply($this->makeZip('3.9.9'), '3.9.9');

		$this->assertSame([], glob($this->appRoot . '.backup-*') ?: [], 'must not leave a backup in the web root');
		$this->assertFileExists($this->appRoot . '/src/New.php', 'the update itself still succeeded');
	}

	public function testRollbackRestoresTheRetainedPreviousVersion(): void
	{
		$this->seedInstall();
		$applier = $this->createApplier($this->appRoot);
		$applier->apply($this->makeZip('3.9.9'), '3.9.9');

		$this->assertFileExists($this->appRoot . '/src/New.php');

		$applier->rollback();

		$this->assertFileExists($this->appRoot . '/src/Old.php');
		$this->assertFileDoesNotExist($this->appRoot . '/src/New.php');
		$this->assertSame('{"title":"keep me"}', (string)file_get_contents($this->appRoot . '/tcms-data/blog/post-1.json'));
	}

	public function testReportsAndDiscardsTheRetainedBackupOnRequest(): void
	{
		$this->seedInstall();
		$applier = $this->createApplier($this->appRoot);
		$applier->apply($this->makeZip('3.9.9'), '3.9.9');

		$info = $applier->retainedBackup();
		$this->assertNotNull($info);
		$this->assertStringStartsWith('3.9.9-', $info['name']);
		$this->assertGreaterThan(0, $info['bytes']);

		$applier->discardRetainedBackup();
		$this->assertNull($applier->retainedBackup());
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	private function createApplier(?string $appRoot = null, ?MaintenanceMode $maintenance = null, ?string $datadir = null): UpdateApplier
	{
		$config          = Config::init();
		$config->datadir = $datadir ?? $this->tmpDir . '/tcms-data';

		return new UpdateApplier(
			$maintenance ?? $this->createMock(MaintenanceMode::class),
			$this->createMock(CacheManager::class),
			new LoggerFactory(['path' => $this->tmpDir . '/logs', 'level' => \Monolog\Level::Debug]),
			$config,
			$appRoot,
		);
	}

	/** Path of the single retained backup, or null. */
	private function retained(?string $datadir = null): ?string
	{
		$dirs = glob(($datadir ?? $this->tmpDir . '/tcms-data') . '/.system/backups/*', GLOB_ONLYDIR);

		return $dirs === false || $dirs === [] ? null : $dirs[0];
	}

	/** A representative install: shipped code + user content + config + logs. */
	private function seedInstall(): void
	{
		$this->write($this->appRoot . '/src/Old.php', '<?php // old code');
		$this->write($this->appRoot . '/public/index.php', '<?php // old index');
		$this->write($this->appRoot . '/tcms-data/blog/post-1.json', '{"title":"keep me"}');
		$this->write($this->appRoot . '/.env', 'APP_ENV=prod');
		$this->write($this->appRoot . '/tcms.php', "<?php return ['site' => 'mine'];");
		$this->write($this->appRoot . '/logs/updates.log', "previous\n");
	}

	private function makeZip(string $version, bool $wrapped = false, bool $includeTcmsData = false): string
	{
		$zipPath = $this->tmpDir . "/update-{$version}.zip";
		$prefix  = $wrapped ? "totalcms-{$version}/" : '';

		$zip = new \ZipArchive();
		$zip->open($zipPath, \ZipArchive::CREATE);
		$zip->addFromString($prefix . 'src/New.php', '<?php // new code');
		$zip->addFromString($prefix . 'public/index.php', '<?php // new index');
		$zip->addFromString($prefix . 'composer.json', '{"name":"totalcms/cms"}');
		if ($includeTcmsData) {
			$zip->addFromString($prefix . 'tcms-data/blog/post-1.json', '{"title":"OVERWRITTEN"}');
		}
		$zip->close();

		return $zipPath;
	}

	private function write(string $path, string $contents): void
	{
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($path, $contents);
	}

	private function deleteDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
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
