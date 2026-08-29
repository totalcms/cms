<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\PushCommand;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Support\OperationResult;
use TotalCMS\TotalCMS;

require_once __DIR__ . '/helpers.php';

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$this->tmpDir = sys_get_temp_dir() . '/tcms-push-test-' . uniqid();
	mkdir($this->tmpDir . '/.system', 0755, true);
	file_put_contents($this->tmpDir . '/.system/settings.json', (string)json_encode([
		'sync' => ['url' => 'https://production.example.com', 'key' => 'test-key'],
	]));

	$this->totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$this->jumpstart = new JumpStartData('Test', 'Test export');
	$this->jumpstart->addSchema(['id' => 'products', 'properties' => ['name' => ['type' => 'string']]]);
	$this->jumpstart->addTemplate(['id' => 'blog-post', 'template' => '<h1>Blog</h1>']);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('exportSyncData')->willReturn($this->jumpstart);
	$exporter->method('setMetadata');
	$this->totalcms->method('jumpStartExporter')->willReturn($exporter);

	// No remote in unit tests: diff() throws so dry-runs exercise the
	// manifest fallback, and the template filter passes through unchanged.
	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('remote unreachable in test'));
	$this->totalcms->method('syncService')->willReturn($syncService);
});

afterEach(function (): void {
	@unlink($this->tmpDir . '/.system/settings.json');
	@rmdir($this->tmpDir . '/.system');
	@rmdir($this->tmpDir);
});

it('shows dry run preview', function (): void {
	$app     = new Application();
	$command = new PushCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true]);

	$output = $tester->getDisplay();
	expect($output)->toContain('Dry run');
	expect($output)->toContain('https://production.example.com');
	expect($output)->toContain('products');
	expect($output)->toContain('blog-post');
	expect($tester->getStatusCode())->toBe(0);
});

it('shows dry run JSON', function (): void {
	$app     = new Application();
	$command = new PushCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--json' => true]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['dry_run'])->toBeTrue();
	expect($data['remote'])->toBe('https://production.example.com');
	expect($data['schemas'])->toContain('products');
	expect($data['templates'])->toContain('blog-post');
});

it('errors when sync not configured', function (): void {
	// Overwrite settings with no sync
	file_put_contents($this->tmpDir . '/.system/settings.json', '{}');

	$app     = new Application();
	$command = new PushCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
});

it('reports nothing to push when empty', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('push')->willReturn(OperationResult::success(
		'Nothing to push — no matching schemas or templates found.',
		['schemas' => 0, 'templates' => 0],
	));
	$totalcms->method('syncService')->willReturn($syncService);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([]);

	$output = $tester->getDisplay();
	expect($output)->toContain('Nothing to push');
	expect($tester->getStatusCode())->toBe(0);
});

it('passes schema filter to exporter on dry-run', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	// syncableTemplateFilter must pass through (narrowing-only in production;
	// the auto-stub would return null and widen [] back to "all"), and the
	// remote fetch throwing keeps the dry-run on the plain manifest path.
	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with(['products'], [], [])
		->willReturn($this->jumpstart);
	$exporter->method('setMetadata');
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--schemas' => 'products', '--dry-run' => true]);
});

it('passes template filter to exporter on dry-run', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	// See the schema-filter test above for why syncService is stubbed.
	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], ['blog-post', 'sidebar'], [])
		->willReturn($this->jumpstart);
	$exporter->method('setMetadata');
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--templates' => 'blog-post,sidebar', '--dry-run' => true]);
});

it('maps feature flags onto the collections filter', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	// A fresh totalcms/syncService/exporter mock trio, per the pattern above
	// (e.g. "passes schema filter to exporter on dry-run") — re-stubbing
	// jumpStartExporter() on $this->totalcms a second time would just add a
	// second matcher behind the one from beforeEach, which always wins.
	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with(
			[],
			[],
			['builder-pages' => null],
			[],
			null,
		)
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--pages' => null]);

	expect($tester->getStatusCode())->toBe(0);
});

it('narrows a feature flag to specific object ids', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with(
			[],
			[],
			['builder-pages' => ['home', 'about']],
			[],
			null,
		)
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--pages' => 'home,about']);

	expect($tester->getStatusCode())->toBe(0);
});

it('sends --collections to the collection SETTINGS filter', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], ['blog'], null)
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--collections' => 'blog']);

	expect($tester->getStatusCode())->toBe(0);
});

it('no longer offers --collection-meta', function (): void {
	$command = new PushCommand($this->totalcms);

	expect($command->getDefinition()->hasOption('collection-meta'))->toBeFalse();
});

it('offers every feature flag', function (string $flag): void {
	$command = new PushCommand($this->totalcms);

	expect($command->getDefinition()->hasOption($flag))->toBeTrue();
})->with(array_keys(\TotalCMS\Domain\Sync\Data\SyncableCollections::FEATURE_FLAGS));

it('parses a bare --objects collection as all objects', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => null])
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--objects' => ['blog']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('parses collection:id,id into an object id filter', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => ['welcome', 'about']])
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--objects' => ['blog:welcome,about']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('accepts --objects more than once', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => ['welcome'], 'faq' => null])
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--objects' => ['blog:welcome', 'faq']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('points at the dedicated flag when --objects names a feature collection', function (): void {
	$app     = new Application();
	$command = new PushCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--objects' => ['builder-pages']]);

	expect($tester->getDisplay())->toContain('--pages');
	expect($tester->getStatusCode())->toBe(1);
});

it('refuses to seed binary-only collections with an explanation', function (): void {
	$app     = new Application();
	$command = new PushCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--objects' => ['image']]);

	expect($tester->getDisplay())->toContain('binaries never travel');
	expect($tester->getStatusCode())->toBe(1);
});

it('widens a bare mention over a previous id list for the same collection', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => null])
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--objects' => ['blog:a', 'blog']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('keeps a bare mention recorded first from being narrowed by a later id list', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => null])
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	// Bare mention FIRST this time — the order that actually exercises the
	// array_key_exists guard: without it, `$filter['blog'] ?? []` treats
	// the already-recorded `null` (all) as if nothing had been recorded,
	// and the merge below would narrow it back down to ['a'].
	$tester->execute(['--dry-run' => true, '--objects' => ['blog', 'blog:a']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('merges id lists for the same collection across repeats', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => ['a', 'b']])
		->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--objects' => ['blog:a', 'blog:b']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('passes the seed filter and overwrite=false to push() on a real push', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->expects($this->once())
		->method('push')
		->with(
			'https://production.example.com',
			'test-key',
			[],
			[],
			[],
			[],
			['blog' => null],
			false,
		)
		->willReturn(OperationResult::success('Pushed', ['schemas' => 0, 'templates' => 0, 'collections' => 0, 'objects' => 1]));
	$totalcms->method('syncService')->willReturn($syncService);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--objects' => ['blog']]);

	expect($tester->getStatusCode())->toBe(0);
});

it('passes overwrite=true to push() when --overwrite is given', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->expects($this->once())
		->method('push')
		->with(
			'https://production.example.com',
			'test-key',
			[],
			[],
			[],
			[],
			['blog' => null],
			true,
		)
		->willReturn(OperationResult::success('Pushed', ['schemas' => 0, 'templates' => 0, 'collections' => 0, 'objects' => 1]));
	$totalcms->method('syncService')->willReturn($syncService);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	// Interactive (CommandTester's default) so the --overwrite guard, which
	// only fires for a non-interactive run without --force, doesn't get in
	// the way of asserting what push() receives.
	$tester->execute(['--objects' => ['blog'], '--overwrite' => true]);

	expect($tester->getStatusCode())->toBe(0);
});

it('refuses --overwrite in a non-interactive run without --force', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->expects($this->never())->method('push');
	$totalcms->method('syncService')->willReturn($syncService);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--objects' => ['blog'], '--overwrite' => true], ['interactive' => false]);

	expect($tester->getDisplay())->toContain('Refusing --overwrite');
	expect($tester->getStatusCode())->toBe(1);
});

it('lets --overwrite through in a non-interactive run with --force', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->expects($this->once())
		->method('push')
		->willReturn(OperationResult::success('Pushed', ['schemas' => 0, 'templates' => 0, 'collections' => 0, 'objects' => 1]));
	$totalcms->method('syncService')->willReturn($syncService);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--objects' => ['blog'], '--overwrite' => true, '--force' => true], ['interactive' => false]);

	expect($tester->getDisplay())->not->toContain('Refusing --overwrite');
	expect($tester->getStatusCode())->toBe(0);
});

it('lets --overwrite through in a non-interactive run with --dry-run', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willThrowException(new \RuntimeException('unreachable in test'));
	$syncService->expects($this->never())->method('push');
	$totalcms->method('syncService')->willReturn($syncService);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->method('exportSyncData')->willReturn($this->jumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--objects' => ['blog'], '--overwrite' => true, '--dry-run' => true], ['interactive' => false]);

	expect($tester->getDisplay())->not->toContain('Refusing --overwrite');
	expect($tester->getStatusCode())->toBe(0);
});

it('shows the seed manifest in dry-run output when the diff succeeds', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$emptyDiff = ['schemas' => [], 'templates' => [], 'objects' => [], 'collections' => []];

	$syncService = $this->createMock(SyncService::class);
	$syncService->method('syncableTemplateFilter')->willReturnArgument(0);
	$syncService->method('diff')->willReturn($emptyDiff);
	$totalcms->method('syncService')->willReturn($syncService);

	$seedJumpstart = new JumpStartData('Test', 'Seed export');
	$seedJumpstart->addObject(['collection' => 'blog', 'id' => 'welcome', 'data' => []]);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->method('setMetadata');
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with([], [], [], [], ['blog' => null])
		->willReturn($seedJumpstart);
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--dry-run' => true, '--objects' => ['blog']]);

	$output = $tester->getDisplay();
	expect($output)->toContain('Seeded objects');
	expect($output)->toContain('blog');
	expect($output)->toContain('welcome');
	expect($output)->not->toContain('Nothing matches');
	expect($tester->getStatusCode())->toBe(0);
});
