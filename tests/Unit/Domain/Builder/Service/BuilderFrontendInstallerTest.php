<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderFrontendInstaller;
use TotalCMS\Support\PathResolver;

final class BuilderFrontendInstallerTest extends TestCase
{
	private string $tmpRoot;
	private ?string $originalPackageRoot;
	private ?string $originalProjectRoot;
	private BuilderFrontendInstaller $installer;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-frontend-test-' . uniqid();
		mkdir($this->tmpRoot, 0755, true);

		// Redirect both PathResolver roots to our tmp dir so the installer
		// reads the scaffold from `<tmp>/resources/builder/frontend/` and
		// writes to `<tmp>/frontend/`. Restored in tearDown.
		$pkgProp                   = new \ReflectionProperty(PathResolver::class, 'packageRoot');
		$projProp                  = new \ReflectionProperty(PathResolver::class, 'projectRoot');
		$this->originalPackageRoot = $pkgProp->getValue();
		$this->originalProjectRoot = $projProp->getValue();
		$pkgProp->setValue(null, $this->tmpRoot);
		$projProp->setValue(null, $this->tmpRoot);

		$this->seedScaffold([
			'vite.config.js'     => "export default {};\n",
			'package.json'       => "{\"name\":\"x\"}\n",
			'src/css/style.css'  => "body { margin: 0; }\n",
			'src/js/app.js'      => "console.log('hi');\n",
			'README.md'          => "# Frontend\n",
		]);

		$this->installer = new BuilderFrontendInstaller();
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(PathResolver::class, 'packageRoot'))
			->setValue(null, $this->originalPackageRoot);
		(new \ReflectionProperty(PathResolver::class, 'projectRoot'))
			->setValue(null, $this->originalProjectRoot);

		$this->rrmdir($this->tmpRoot);
	}

	public function testFailsWhenScaffoldDirectoryMissing(): void
	{
		$this->rrmdir($this->tmpRoot . '/resources/builder/frontend');

		$result = $this->installer->install();

		$this->assertFalse($result->success);
		$this->assertStringContainsString('scaffold missing', $result->message);
	}

	public function testCopiesEntireScaffoldToFrontendDir(): void
	{
		$result = $this->installer->install();

		$this->assertTrue($result->success);
		$this->assertFileExists($this->tmpRoot . '/frontend/vite.config.js');
		$this->assertFileExists($this->tmpRoot . '/frontend/package.json');
		$this->assertFileExists($this->tmpRoot . '/frontend/src/css/style.css');
		$this->assertFileExists($this->tmpRoot . '/frontend/src/js/app.js');
		$this->assertFileExists($this->tmpRoot . '/frontend/README.md');

		$this->assertEquals(
			"body { margin: 0; }\n",
			file_get_contents($this->tmpRoot . '/frontend/src/css/style.css'),
		);
	}

	public function testCreatesNestedDirectoriesAsNeeded(): void
	{
		$result = $this->installer->install();

		$this->assertTrue($result->success);
		$this->assertDirectoryExists($this->tmpRoot . '/frontend/src');
		$this->assertDirectoryExists($this->tmpRoot . '/frontend/src/css');
		$this->assertDirectoryExists($this->tmpRoot . '/frontend/src/js');
	}

	public function testIdempotentSkipsExistingFilesByDefault(): void
	{
		// Pre-populate with a custom version of one file.
		mkdir($this->tmpRoot . '/frontend', 0755, true);
		file_put_contents($this->tmpRoot . '/frontend/vite.config.js', '/* customized */');

		$result = $this->installer->install();

		$this->assertTrue($result->success);
		// User's customization is preserved.
		$this->assertSame('/* customized */', file_get_contents($this->tmpRoot . '/frontend/vite.config.js'));
		// Other files were copied.
		$this->assertFileExists($this->tmpRoot . '/frontend/package.json');
		// Reported as skipped.
		$this->assertContains('vite.config.js', $result->data['skipped'] ?? []);
		$this->assertStringContainsString('--force', $result->message);
	}

	public function testForceOverwritesExistingFiles(): void
	{
		mkdir($this->tmpRoot . '/frontend', 0755, true);
		file_put_contents($this->tmpRoot . '/frontend/vite.config.js', '/* customized */');

		$result = $this->installer->install(force: true);

		$this->assertTrue($result->success);
		$this->assertSame(
			"export default {};\n",
			file_get_contents($this->tmpRoot . '/frontend/vite.config.js'),
		);
		$this->assertContains('vite.config.js', $result->data['copied'] ?? []);
	}

	public function testCreatesTargetDirectoryWhenMissing(): void
	{
		$this->assertDirectoryDoesNotExist($this->tmpRoot . '/frontend');

		$result = $this->installer->install();

		$this->assertTrue($result->success);
		$this->assertDirectoryExists($this->tmpRoot . '/frontend');
	}

	public function testReportsTargetPathInResult(): void
	{
		$result = $this->installer->install();

		$this->assertSame($this->tmpRoot . '/frontend', $result->data['target'] ?? null);
	}

	/** @param array<string,string> $files */
	private function seedScaffold(array $files): void
	{
		$root = $this->tmpRoot . '/resources/builder/frontend';
		mkdir($root, 0755, true);
		foreach ($files as $relative => $contents) {
			$full = $root . '/' . $relative;
			$dir  = dirname($full);
			if (!is_dir($dir)) {
				mkdir($dir, 0755, true);
			}
			file_put_contents($full, $contents);
		}
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
