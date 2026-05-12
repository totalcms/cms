<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Builder\Service\StarterService;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

final class StarterServiceTest extends TestCase
{
	private string $tmpRoot;
	private ?string $originalPackageRoot;

	private BuilderConfigService&MockObject $config;
	private BuilderInstaller&MockObject $installer;
	private TemplateLister&MockObject $templateLister;
	private TemplateMigrationService&MockObject $templateMigration;
	private JumpStartImporter&MockObject $jumpStart;
	private StarterService $service;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-starter-test-' . uniqid();
		mkdir($this->tmpRoot . '/resources/builder/starters', 0755, true);

		// Redirect StarterService::startersDir() to our tmp dir by overriding
		// the cached PathResolver::$packageRoot static. Restored in tearDown.
		$prop                       = new \ReflectionProperty(PathResolver::class, 'packageRoot');
		$this->originalPackageRoot  = $prop->getValue();
		$prop->setValue(null, $this->tmpRoot);

		$this->config            = $this->createMock(BuilderConfigService::class);
		$this->installer         = $this->createMock(BuilderInstaller::class);
		$this->templateLister    = $this->createMock(TemplateLister::class);
		$this->templateMigration = $this->createMock(TemplateMigrationService::class);
		$this->jumpStart         = $this->createMock(JumpStartImporter::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		// docroot points inside the tmp dir so asset copies land where we can
		// inspect them. assetsPath stays at the default 'assets'.
		$this->config->method('getDocroot')->willReturn($this->tmpRoot . '/public');
		$this->config->method('getAssetsPath')->willReturn('assets');
		mkdir($this->tmpRoot . '/public', 0755, true);
		// listBuilderTemplates returning [] = "no existing templates" (default).
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$this->service = new StarterService(
			$this->config,
			$this->installer,
			$this->templateLister,
			$this->templateMigration,
			$this->jumpStart,
			$loggerFactory,
		);
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(PathResolver::class, 'packageRoot'))
			->setValue(null, $this->originalPackageRoot);

		$this->rrmdir($this->tmpRoot);
	}

	// --- listStarters ---

	public function testListStartersReturnsEmptyWhenDirIsAbsent(): void
	{
		$this->rrmdir($this->tmpRoot . '/resources/builder/starters');

		$this->assertSame([], $this->service->listStarters());
	}

	public function testListStartersReturnsValidManifests(): void
	{
		$this->writeManifest('blog', ['name' => 'Blog', 'description' => 'A blog']);
		$this->writeManifest('portfolio', ['name' => 'Portfolio']);

		$starters = $this->service->listStarters();

		$this->assertCount(2, $starters);
		$names = array_map(static fn (\TotalCMS\Domain\Builder\Data\StarterManifest $s): string => $s->name, $starters);
		$this->assertContains('Blog', $names);
		$this->assertContains('Portfolio', $names);
	}

	public function testListStartersSkipsDirectoriesWithoutManifest(): void
	{
		mkdir($this->tmpRoot . '/resources/builder/starters/no-manifest', 0755, true);
		$this->writeManifest('blog', ['name' => 'Blog']);

		$starters = $this->service->listStarters();

		$this->assertCount(1, $starters);
		$this->assertSame('Blog', $starters[0]->name);
	}

	public function testListStartersSkipsMalformedManifests(): void
	{
		$path = $this->tmpRoot . '/resources/builder/starters/broken';
		mkdir($path, 0755, true);
		file_put_contents($path . '/manifest.json', 'not json');
		$this->writeManifest('blog', ['name' => 'Blog']);

		$starters = $this->service->listStarters();

		$this->assertCount(1, $starters);
		$this->assertSame('Blog', $starters[0]->name);
	}

	// --- scaffold: errors ---

	public function testScaffoldFailsForUnknownStarter(): void
	{
		$result = $this->service->scaffold('nope');

		$this->assertFalse($result->success);
		$this->assertStringContainsString("'nope' not found", $result->message);
	}

	public function testScaffoldFailsWhenTemplatesAlreadyExistAndNotForced(): void
	{
		$this->writeManifest('blog', ['name' => 'Blog']);

		$lister = $this->createMock(TemplateLister::class);
		$lister->method('listBuilderTemplates')
			->with('pages')
			->willReturn(['existing']);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$service = new StarterService(
			$this->config,
			$this->installer,
			$lister,
			$this->templateMigration,
			$this->jumpStart,
			$loggerFactory,
		);

		$result = $service->scaffold('blog');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Templates already exist', $result->message);
	}

	// --- scaffold: happy path ---

	public function testScaffoldCopiesTemplatesAndEnsuresCollection(): void
	{
		$this->writeManifest('blog', ['name' => 'Blog']);

		// Each category gets imported.
		$this->templateMigration->expects($this->exactly(4))
			->method('importDirectory')
			->willReturn(2);

		$this->installer->expects($this->once())->method('ensurePagesCollection');

		// No jumpstart.json on disk → importer should not be called.
		$this->jumpStart->expects($this->never())->method('importFromFile');

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(8, $result->data['filesCopied'] ?? null);
		$this->assertArrayHasKey('jumpStart', $result->data);
		$this->assertNull($result->data['jumpStart']);
	}

	// --- scaffold: jumpstart import ---

	public function testJumpStartImportedWhenFileExists(): void
	{
		$this->writeManifest('blog', []);
		$this->writeJumpstart('blog', ['name' => 'Blog']);

		$this->jumpStart->expects($this->once())
			->method('importFromFile')
			->with($this->stringContains('starters/blog/jumpstart.json'))
			->willReturn(OperationResult::success('Imported'));

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(['ok' => true], $result->data['jumpStart'] ?? null);
		$this->assertStringContainsString('jumpstart imported', $result->message);
	}

	public function testJumpStartSkippedWhenFileMissing(): void
	{
		$this->writeManifest('blog', []);
		// No jumpstart.json — importer should not be called.

		$this->jumpStart->expects($this->never())->method('importFromFile');

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertArrayHasKey('jumpStart', $result->data);
		$this->assertNull($result->data['jumpStart']);
	}

	public function testJumpStartImporterFailureSurfacesAsWarningButScaffoldStillSucceeds(): void
	{
		$this->writeManifest('blog', []);
		$this->writeJumpstart('blog', ['name' => 'Blog']);

		// Importer reports an error — scaffold should still succeed (templates
		// are already on disk; the user can re-run jumpstart manually).
		$this->jumpStart->method('importFromFile')
			->willReturn(OperationResult::failure('schema invalid'));

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success, 'Scaffold should succeed even when jumpstart import fails');
		$this->assertSame(['ok' => false, 'error' => 'schema invalid'], $result->data['jumpStart'] ?? null);
		$this->assertStringContainsString('jumpstart import failed', $result->message);
	}

	public function testJumpStartImporterThrowingIsCaught(): void
	{
		$this->writeManifest('blog', []);
		$this->writeJumpstart('blog', ['name' => 'Blog']);

		$this->jumpStart->method('importFromFile')
			->willThrowException(new \RuntimeException('disk full'));

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertFalse($result->data['jumpStart']['ok'] ?? true);
		$this->assertStringContainsString('disk full', $result->data['jumpStart']['error'] ?? '');
	}

	// --- scaffold: asset copying ---

	public function testCopiesStarterAssetsIntoDocrootAssetsDir(): void
	{
		$this->writeManifest('blog', []);
		$this->writeAsset('blog', 'style.css', 'body { color: red; }');
		$this->writeAsset('blog', 'js/app.js', 'console.log("hi");');

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(2, $result->data['assetsCopied'] ?? null);
		$this->assertFileExists($this->tmpRoot . '/public/assets/style.css');
		$this->assertFileExists($this->tmpRoot . '/public/assets/js/app.js');
		$this->assertSame('body { color: red; }', file_get_contents($this->tmpRoot . '/public/assets/style.css'));
	}

	public function testStarterWithoutAssetsDirIsHandledCleanly(): void
	{
		$this->writeManifest('blog', []);

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(0, $result->data['assetsCopied'] ?? null);
	}

	public function testAssetCopySkipsExistingFilesWithoutForce(): void
	{
		$this->writeManifest('blog', []);
		$this->writeAsset('blog', 'style.css', 'body { color: red; }');

		mkdir($this->tmpRoot . '/public/assets', 0755, true);
		file_put_contents($this->tmpRoot . '/public/assets/style.css', '/* customized */');

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(0, $result->data['assetsCopied'] ?? null);
		$this->assertSame('/* customized */', file_get_contents($this->tmpRoot . '/public/assets/style.css'));
	}

	public function testForceOverwritesExistingAssets(): void
	{
		$this->writeManifest('blog', []);
		$this->writeAsset('blog', 'style.css', 'body { color: red; }');

		mkdir($this->tmpRoot . '/public/assets', 0755, true);
		file_put_contents($this->tmpRoot . '/public/assets/style.css', '/* customized */');

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $this->service->scaffold('blog', force: true);

		$this->assertTrue($result->success);
		$this->assertSame(1, $result->data['assetsCopied'] ?? null);
		$this->assertSame('body { color: red; }', file_get_contents($this->tmpRoot . '/public/assets/style.css'));
	}

	public function testAssetCopySkippedWhenDocrootIsBlank(): void
	{
		$config = $this->createMock(BuilderConfigService::class);
		$config->method('getDocroot')->willReturn('');
		$config->method('getAssetsPath')->willReturn('assets');

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$service = new StarterService(
			$config,
			$this->installer,
			$this->templateLister,
			$this->templateMigration,
			$this->jumpStart,
			$loggerFactory,
		);

		$this->writeManifest('blog', []);
		$this->writeAsset('blog', 'style.css', 'body {}');

		$this->templateMigration->method('importDirectory')->willReturn(0);

		$result = $service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(0, $result->data['assetsCopied'] ?? null);
	}

	// --- helpers ---

	/** @param array<string,mixed> $data */
	private function writeManifest(string $name, array $data): void
	{
		$dir = $this->tmpRoot . '/resources/builder/starters/' . $name;
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/manifest.json', (string)json_encode($data));
	}

	/** @param array<string,mixed> $data */
	private function writeJumpstart(string $name, array $data): void
	{
		$dir = $this->tmpRoot . '/resources/builder/starters/' . $name;
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($dir . '/jumpstart.json', (string)json_encode($data));
	}

	private function writeAsset(string $starter, string $relative, string $contents): void
	{
		$path = $this->tmpRoot . '/resources/builder/starters/' . $starter . '/assets/' . $relative;
		$dir  = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($path, $contents);
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
