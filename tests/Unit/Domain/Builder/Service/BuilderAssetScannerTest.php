<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderAssetScanner;
use TotalCMS\Support\Config;

final class BuilderAssetScannerTest extends TestCase
{
	private string $tmpDir;
	private Config $config;
	private BuilderAssetScanner $scanner;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-asset-scan-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);

		$this->config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->docroot = $this->tmpDir;

		$this->scanner = new BuilderAssetScanner($this->config);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpDir);
	}

	public function testReturnsEmptyResultWhenAssetsDirIsMissing(): void
	{
		$result = $this->scanner->scan('assets');

		$this->assertSame([], $result['css']);
		$this->assertSame([], $result['js']);
		$this->assertSame([], $result['fonts']);
		$this->assertSame([], $result['images']);
		$this->assertSame([], $result['other']);
		$this->assertFalse($result['hasManifest']);
	}

	public function testClassifiesFilesByExtension(): void
	{
		$this->seed('assets', [
			'styles.css'    => '',
			'app.scss'      => '',
			'theme.less'    => '',
			'app.js'        => '',
			'module.mjs'    => '',
			'types.ts'      => '',
			'font.woff2'    => '',
			'font.ttf'      => '',
			'font.otf'      => '',
			'font.eot'      => '',
			'logo.png'      => '',
			'photo.jpg'     => '',
			'photo.jpeg'    => '',
			'icon.svg'      => '',
			'shot.webp'     => '',
			'pic.gif'       => '',
			'av.avif'       => '',
			'fav.ico'       => '',
			'README.txt'    => '',
			'data.xml'      => '',
		]);

		$result = $this->scanner->scan('assets');

		$this->assertEqualsCanonicalizing(['app.scss', 'styles.css', 'theme.less'], $result['css']);
		$this->assertEqualsCanonicalizing(['app.js', 'module.mjs', 'types.ts'], $result['js']);
		$this->assertEqualsCanonicalizing(['font.eot', 'font.otf', 'font.ttf', 'font.woff2'], $result['fonts']);
		$this->assertEqualsCanonicalizing(
			['av.avif', 'fav.ico', 'icon.svg', 'logo.png', 'photo.jpeg', 'photo.jpg', 'pic.gif', 'shot.webp'],
			$result['images'],
		);
		$this->assertEqualsCanonicalizing(['README.txt', 'data.xml'], $result['other']);
	}

	public function testDetectsManifest(): void
	{
		$this->seed('assets', ['manifest.json' => '{}', 'app.js' => '']);

		$result = $this->scanner->scan('assets');

		$this->assertTrue($result['hasManifest']);
		// manifest itself is not listed in any group
		$this->assertSame(['app.js'], $result['js']);
		$this->assertSame([], $result['other']);
	}

	public function testSkipsHiddenFiles(): void
	{
		$this->seed('assets', [
			'.gitkeep'      => '',
			'.DS_Store'     => '',
			'app.css'       => '',
		]);

		$result = $this->scanner->scan('assets');

		$this->assertSame(['app.css'], $result['css']);
		$this->assertSame([], $result['other']);
	}

	public function testRecursesIntoSubdirectoriesAndUsesRelativePaths(): void
	{
		$this->seed('assets', [
			'app.css'              => '',
			'css/components.css'   => '',
			'css/forms/inputs.css' => '',
			'js/vendor/lib.js'     => '',
		]);

		$result = $this->scanner->scan('assets');

		$this->assertContains('app.css', $result['css']);
		$this->assertContains('css/components.css', $result['css']);
		$this->assertContains('css/forms/inputs.css', $result['css']);
		$this->assertContains('js/vendor/lib.js', $result['js']);
	}

	public function testGroupsAreSorted(): void
	{
		$this->seed('assets', [
			'z.css' => '',
			'a.css' => '',
			'm.css' => '',
		]);

		$result = $this->scanner->scan('assets');

		$this->assertSame(['a.css', 'm.css', 'z.css'], $result['css']);
	}

	public function testCustomAssetsPathRespected(): void
	{
		$this->seed('public-assets', ['app.css' => '']);

		$result = $this->scanner->scan('public-assets');

		$this->assertSame(['app.css'], $result['css']);
	}

	public function testExtensionMatchingIsCaseInsensitive(): void
	{
		$this->seed('assets', [
			'STYLES.CSS' => '',
			'APP.JS'     => '',
		]);

		$result = $this->scanner->scan('assets');

		$this->assertSame(['STYLES.CSS'], $result['css']);
		$this->assertSame(['APP.JS'], $result['js']);
	}

	/**
	 * @param array<string,string> $files relative path => contents
	 */
	private function seed(string $assetsPath, array $files): void
	{
		$root = $this->tmpDir . '/' . $assetsPath;
		if (!is_dir($root)) {
			mkdir($root, 0755, true);
		}
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
