<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\PushCommand;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\TotalCMS;

use function Tests\Unit\CLI\Command\createTestConfig;

require_once __DIR__ . '/helpers.php';

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$this->tmpDir = sys_get_temp_dir() . '/tcms-push-test-' . uniqid();
	mkdir($this->tmpDir . '/.system', 0755, true);
	file_put_contents($this->tmpDir . '/.system/settings.json', (string) json_encode([
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

	$emptyJumpstart = new JumpStartData();
	$exporter       = $this->createMock(JumpStartExporter::class);
	$exporter->method('exportSyncData')->willReturn($emptyJumpstart);
	$exporter->method('setMetadata');
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([]);

	$output = $tester->getDisplay();
	expect($output)->toContain('Nothing to push');
	expect($tester->getStatusCode())->toBe(0);
});

it('passes schema filter to exporter', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with(['products'], null)
		->willReturn($this->jumpstart);
	$exporter->method('setMetadata');
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--schemas' => 'products', '--dry-run' => true]);
});

it('passes template filter to exporter', function (): void {
	$totalcms         = $this->createMock(TotalCMS::class);
	$totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);

	$exporter = $this->createMock(JumpStartExporter::class);
	$exporter->expects($this->once())
		->method('exportSyncData')
		->with(null, ['blog-post', 'sidebar'])
		->willReturn($this->jumpstart);
	$exporter->method('setMetadata');
	$totalcms->method('jumpStartExporter')->willReturn($exporter);

	$app     = new Application();
	$command = new PushCommand($totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['--templates' => 'blog-post,sidebar', '--dry-run' => true]);
});
