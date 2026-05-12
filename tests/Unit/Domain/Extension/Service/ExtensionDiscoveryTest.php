<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;
use TotalCMS\Support\Version;

/**
 * Focused tests for the bundled-vs-user discovery path. Broader extension
 * lifecycle tests live in ExtensionManagerTest.
 */
final class ExtensionDiscoveryTest extends TestCase
{
	private string $tmpRoot;
	private ?string $originalPackageRoot;
	private string $userExtensionsDir;
	private string $bundledExtensionsDir;
	private ExtensionDiscovery $discovery;

	protected function setUp(): void
	{
		$this->tmpRoot              = sys_get_temp_dir() . '/tcms-extdiscovery-' . uniqid();
		$this->userExtensionsDir    = $this->tmpRoot . '/tcms-data/extensions';
		$this->bundledExtensionsDir = $this->tmpRoot . '/resources/extensions';
		mkdir($this->userExtensionsDir, 0755, true);
		mkdir($this->bundledExtensionsDir, 0755, true);

		// Redirect PathResolver::packageRoot so getBundledExtensionsDirectory()
		// points at our tmp dir rather than the real package — keeps the test
		// hermetic and stops it from picking up actual bundled extensions.
		$prop                      = new \ReflectionProperty(PathResolver::class, 'packageRoot');
		$this->originalPackageRoot = $prop->getValue();
		$prop->setValue(null, $this->tmpRoot);

		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->tmpRoot . '/tcms-data';

		$validator       = new ManifestValidator($this->createMock(EditionFeatureService::class));
		$this->discovery = new ExtensionDiscovery($config, $validator, new NullLogger());
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(PathResolver::class, 'packageRoot'))
			->setValue(null, $this->originalPackageRoot);

		$this->rrmdir($this->tmpRoot);
	}

	public function testEmptyDirsReturnEmpty(): void
	{
		$this->assertSame([], $this->discovery->discover());
	}

	public function testFlagsBundledExtensions(): void
	{
		$this->writeManifest($this->bundledExtensionsDir, 'totalcms', 'ab-split', [
			'id'   => 'totalcms/ab-split',
			'name' => 'A/B Split',
		]);

		$manifests = $this->discovery->discover();

		$this->assertArrayHasKey('totalcms/ab-split', $manifests);
		$this->assertTrue($manifests['totalcms/ab-split']->bundled);
	}

	public function testBundledExtensionsReportT3Version(): void
	{
		// Bundled extensions ship in the package, so their version IS the T3
		// version. Whatever's in the manifest JSON gets overridden.
		$this->writeManifest($this->bundledExtensionsDir, 'totalcms', 'ab-split', [
			'id'      => 'totalcms/ab-split',
			'version' => '99.99.99', // pretending an out-of-date manifest version
		]);

		$manifests = $this->discovery->discover();

		$this->assertArrayHasKey('totalcms/ab-split', $manifests);
		$this->assertSame(Version::number(), $manifests['totalcms/ab-split']->version);
	}

	public function testUserExtensionsKeepTheirManifestVersion(): void
	{
		// User-installed extensions are versioned independently of T3.
		$this->writeManifest($this->userExtensionsDir, 'acme', 'thing', [
			'id'      => 'acme/thing',
			'version' => '2.5.0',
		]);

		$manifests = $this->discovery->discover();

		$this->assertSame('2.5.0', $manifests['acme/thing']->version);
	}

	public function testUserExtensionsAreNotFlaggedBundled(): void
	{
		$this->writeManifest($this->userExtensionsDir, 'acme', 'thing', [
			'id'   => 'acme/thing',
			'name' => 'Acme Thing',
		]);

		$manifests = $this->discovery->discover();

		$this->assertArrayHasKey('acme/thing', $manifests);
		$this->assertFalse($manifests['acme/thing']->bundled);
	}

	public function testUserInstalledOverridesBundledOnIdCollision(): void
	{
		// Both paths declare totalcms/ab-split. User wins — admin can shadow
		// a bundled extension to patch a bug locally before the next release.
		$this->writeManifest($this->bundledExtensionsDir, 'totalcms', 'ab-split', [
			'id'      => 'totalcms/ab-split',
			'name'    => 'Bundled Version',
			'version' => '1.0.0',
		]);
		$this->writeManifest($this->userExtensionsDir, 'totalcms', 'ab-split', [
			'id'      => 'totalcms/ab-split',
			'name'    => 'User Override',
			'version' => '1.0.0-patched',
		]);

		$manifests = $this->discovery->discover();
		$override  = $manifests['totalcms/ab-split'] ?? null;

		$this->assertNotNull($override);
		$this->assertSame('User Override', $override->name);
		$this->assertSame('1.0.0-patched', $override->version);
		// User-installed wins on collision so the override is NOT marked bundled.
		$this->assertFalse($override->bundled);
	}

	public function testBundledAndUserCoexistWhenDifferentIds(): void
	{
		$this->writeManifest($this->bundledExtensionsDir, 'totalcms', 'ab-split', [
			'id' => 'totalcms/ab-split',
		]);
		$this->writeManifest($this->userExtensionsDir, 'acme', 'custom', [
			'id' => 'acme/custom',
		]);

		$manifests = $this->discovery->discover();

		$this->assertCount(2, $manifests);
		$this->assertTrue($manifests['totalcms/ab-split']->bundled);
		$this->assertFalse($manifests['acme/custom']->bundled);
	}

	public function testGetExtensionPathFindsBundled(): void
	{
		$this->writeManifest($this->bundledExtensionsDir, 'totalcms', 'ab-split', ['id' => 'totalcms/ab-split']);
		$this->discovery->discover();

		$path = $this->discovery->getExtensionPath('totalcms/ab-split');

		$this->assertSame($this->bundledExtensionsDir . '/totalcms/ab-split', $path);
	}

	public function testGetExtensionPathFallbackChecksBothDirs(): void
	{
		// Don't call discover() first — exercise the fallback path that
		// reconstructs from the id.
		mkdir($this->bundledExtensionsDir . '/totalcms/ab-split', 0755, true);

		$this->assertSame(
			$this->bundledExtensionsDir . '/totalcms/ab-split',
			$this->discovery->getExtensionPath('totalcms/ab-split'),
		);
	}

	public function testGetExtensionPathReturnsNullForUnknownExtension(): void
	{
		$this->assertNull($this->discovery->getExtensionPath('nope/missing'));
	}

	public function testMissingManifestFileSkipsExtension(): void
	{
		mkdir($this->bundledExtensionsDir . '/totalcms/no-manifest', 0755, true);

		$this->assertSame([], $this->discovery->discover());
	}

	public function testInvalidJsonManifestSkipsExtension(): void
	{
		$dir = $this->bundledExtensionsDir . '/totalcms/broken';
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/extension.json', 'not json');

		$this->assertSame([], $this->discovery->discover());
	}

	/**
	 * @param array<string,mixed> $extra
	 */
	private function writeManifest(string $base, string $vendor, string $name, array $extra): void
	{
		$dir = $base . '/' . $vendor . '/' . $name;
		mkdir($dir, 0755, true);

		$manifest = array_merge([
			'name'        => 'Test',
			'description' => 'Test extension',
			'version'     => '1.0.0',
			'requires'    => ['totalcms' => '>=3.0.0', 'php' => '>=8.2'],
			'entrypoint'  => 'Extension.php',
			'license'     => 'MIT',
		], $extra);

		file_put_contents($dir . '/extension.json', (string)json_encode($manifest));
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
