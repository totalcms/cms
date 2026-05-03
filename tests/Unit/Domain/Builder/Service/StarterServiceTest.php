<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Builder\Service\StarterService;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\PathResolver;

final class StarterServiceTest extends TestCase
{
	private string $tmpRoot;
	private ?string $originalPackageRoot;

	private BuilderConfigService&MockObject $config;
	private BuilderInstaller&MockObject $installer;
	private BuilderOrderService&MockObject $orderService;
	private ObjectSaver&MockObject $objectSaver;
	private ObjectUpdater&MockObject $objectUpdater;
	private TemplateLister&MockObject $templateLister;
	private TemplateMigrationService&MockObject $templateMigration;
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
		$this->orderService      = $this->createMock(BuilderOrderService::class);
		$this->objectSaver       = $this->createMock(ObjectSaver::class);
		$this->objectUpdater     = $this->createMock(ObjectUpdater::class);
		$this->templateLister    = $this->createMock(TemplateLister::class);
		$this->templateMigration = $this->createMock(TemplateMigrationService::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->config->method('getPagesCollectionId')->willReturn('builder-pages');
		// listBuilderTemplates returning [] = "no existing templates" (default).
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$this->service = new StarterService(
			$this->config,
			$this->installer,
			$this->orderService,
			$this->objectSaver,
			$this->objectUpdater,
			$this->templateLister,
			$this->templateMigration,
			$loggerFactory,
		);
	}

	protected function tearDown(): void
	{
		// Restore PathResolver state so other tests aren't affected.
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
		$names = array_map(static fn ($s) => $s->name, $starters);
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
		$this->templateLister = $this->createMock(TemplateLister::class);
		$this->templateLister->method('listBuilderTemplates')
			->with('pages')
			->willReturn(['existing']);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		// Rebuild service with the new lister.
		$service = new StarterService(
			$this->config,
			$this->installer,
			$this->orderService,
			$this->objectSaver,
			$this->objectUpdater,
			$this->templateLister,
			$this->templateMigration,
			$loggerFactory,
		);

		$result = $service->scaffold('blog');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Templates already exist', $result->message);
	}

	// --- scaffold: happy path ---

	public function testScaffoldCopiesTemplatesAndCreatesPages(): void
	{
		$this->writeManifest('blog', [
			'name'  => 'Blog',
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index'],
				['id' => 'post', 'title' => 'Post', 'route' => '/blog/{id}', 'template' => 'blog/post'],
			],
		]);

		// Each category gets imported.
		$this->templateMigration->expects($this->exactly(4))
			->method('importDirectory')
			->willReturn(2);

		$this->installer->expects($this->once())->method('ensurePagesCollection');

		$this->objectSaver->expects($this->exactly(2))->method('saveObject');
		$this->objectUpdater->expects($this->never())->method('updateObject');

		$this->orderService->expects($this->once())
			->method('write')
			->with(
				'builder-pages',
				[
					['id' => 'home', 'children' => []],
					['id' => 'post', 'children' => []],
				],
			);

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(8, $result->data['filesCopied'] ?? null);
		$this->assertSame(2, $result->data['pagesCreated'] ?? null);
	}

	// --- scaffold: --force update path ---

	public function testForceUpdatesPagesThatAlreadyExist(): void
	{
		$this->writeManifest('blog', [
			'name'  => 'Blog',
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index'],
				['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about'],
			],
		]);

		$this->templateMigration->method('importDirectory')->willReturn(0);

		// Both pages already exist — saveObject throws DomainException.
		$this->objectSaver->method('saveObject')
			->willThrowException(new \DomainException('Object with id home already exists in builder-pages'));

		// With --force, both should fall through to updateObject.
		$this->objectUpdater->expects($this->exactly(2))
			->method('updateObject')
			->willReturnCallback(static function (string $col, string $id, array $record) {
				$mock = (new \ReflectionClass(\TotalCMS\Domain\Object\Data\ObjectData::class))
					->newInstanceWithoutConstructor();

				return $mock;
			});

		$result = $this->service->scaffold('blog', force: true);

		$this->assertTrue($result->success);
		$this->assertSame(2, $result->data['pagesCreated'] ?? null);
	}

	public function testWithoutForceDuplicatesAreSilentlySkipped(): void
	{
		$this->writeManifest('blog', [
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index'],
				['id' => 'new', 'title' => 'New', 'route' => '/new', 'template' => 'new'],
			],
		]);

		$this->templateMigration->method('importDirectory')->willReturn(0);

		// First page exists, second doesn't.
		$call = 0;
		$this->objectSaver->method('saveObject')->willReturnCallback(function () use (&$call) {
			$call++;
			if ($call === 1) {
				throw new \DomainException('Object with id home already exists');
			}

			return (new \ReflectionClass(\TotalCMS\Domain\Object\Data\ObjectData::class))
				->newInstanceWithoutConstructor();
		});

		// Without --force, no updateObject calls.
		$this->objectUpdater->expects($this->never())->method('updateObject');

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(1, $result->data['pagesCreated'] ?? null);
	}

	// --- scaffold: order seeding ---

	public function testOrderFileSeededEvenWhenNoPagesWereCreated(): void
	{
		// All pages already exist + no --force = nothing gets created. The
		// order file should still be written so the sidebar reflects the
		// manifest's order.
		$this->writeManifest('blog', [
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index'],
				['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about'],
			],
		]);

		$this->templateMigration->method('importDirectory')->willReturn(0);
		$this->objectSaver->method('saveObject')
			->willThrowException(new \DomainException('exists'));

		$this->orderService->expects($this->once())
			->method('write')
			->with(
				'builder-pages',
				[
					['id' => 'home', 'children' => []],
					['id' => 'about', 'children' => []],
				],
			);

		$this->service->scaffold('blog');
	}

	public function testOrderFileNotWrittenWhenAllManifestPagesHaveEmptyIds(): void
	{
		// Empty-id pages get skipped from BOTH createPageObjects and
		// seedOrderFile — when nothing is left, write isn't called.
		$this->writeManifest('blog', [
			'pages' => [
				['title' => 'No ID 1'],
				['title' => 'No ID 2'],
			],
		]);

		$this->templateMigration->method('importDirectory')->willReturn(0);
		$this->orderService->expects($this->never())->method('write');

		$result = $this->service->scaffold('blog');

		$this->assertTrue($result->success);
		$this->assertSame(0, $result->data['pagesCreated'] ?? null);
	}

	// --- scaffold: empty IDs skipped ---

	public function testEmptyPageIdsSkippedFromCreation(): void
	{
		$this->writeManifest('blog', [
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'route' => '/', 'template' => 'index'],
				['title' => 'Missing ID'],
				['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'about'],
			],
		]);

		$this->templateMigration->method('importDirectory')->willReturn(0);

		// Only home and about saved; the empty-id one is skipped.
		$this->objectSaver->expects($this->exactly(2))->method('saveObject');

		$this->orderService->expects($this->once())
			->method('write')
			->with(
				'builder-pages',
				[
					['id' => 'home', 'children' => []],
					['id' => 'about', 'children' => []],
				],
			);

		$result = $this->service->scaffold('blog');

		$this->assertSame(2, $result->data['pagesCreated'] ?? null);
	}

	// --- helpers ---

	/** @param array<string,mixed> $data */
	private function writeManifest(string $name, array $data): void
	{
		$dir = $this->tmpRoot . '/resources/builder/starters/' . $name;
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/manifest.json', (string)json_encode($data));
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
