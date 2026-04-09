<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\JumpStartExportCommand;
use TotalCMS\CLI\Command\JumpStartImportCommand;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\TotalCMS;

describe('jumpstart:export', function (): void {
	beforeEach(function (): void {
		$this->totalcms = $this->createMock(TotalCMS::class);

		$jumpstart = new JumpStartData('Test', 'Test export');
		$jumpstart->addSchema(['id' => 'products', 'properties' => ['name' => ['type' => 'string']]]);
		$jumpstart->addReservedCollection('blog');
		$jumpstart->addTemplate(['id' => 'post', 'template' => '<h1>Post</h1>']);
		$jumpstart->addObject(['collection' => 'blog', 'id' => 'post-1', 'data' => ['title' => 'Hello']]);

		$exporter = $this->createMock(JumpStartExporter::class);
		$exporter->method('exportCurrentData')->willReturn($jumpstart);
		$exporter->method('setMetadata');
		$this->totalcms->method('jumpStartExporter')->willReturn($exporter);

		$app     = new Application();
		$command = new JumpStartExportCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('exports to a file', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-js-export-' . uniqid() . '.json';

		$this->tester->execute(['--output' => $tmpFile]);

		expect(file_exists($tmpFile))->toBeTrue();
		$data = json_decode((string)file_get_contents($tmpFile), true);
		expect($data)->toHaveKey('schemas');
		expect($data)->toHaveKey('templates');
		expect($data)->toHaveKey('objects');
		expect($this->tester->getDisplay())->toContain('exported');
		@unlink($tmpFile);
	});

	it('generates default filename when --output not set', function (): void {
		$this->tester->execute([]);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('jumpstart-');
		expect($output)->toContain('.json');

		// Clean up generated file
		preg_match('/jumpstart-[^\s]+\.json/', $output, $matches);
		if (isset($matches[0]) && file_exists($matches[0])) {
			@unlink($matches[0]);
		}
	});

	it('outputs JSON summary with --json', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-js-export-' . uniqid() . '.json';

		$this->tester->execute(['--output' => $tmpFile, '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data['success'])->toBeTrue();
		expect($data['schemas'])->toBe(1);
		expect($data['collections'])->toBe(1);
		expect($data['objects'])->toBe(1);
		expect($data['templates'])->toBe(1);
		@unlink($tmpFile);
	});
});

describe('jumpstart:import', function (): void {
	beforeEach(function (): void {
		$this->totalcms = $this->createMock(TotalCMS::class);

		$importer = $this->createMock(JumpStartImporter::class);
		$importer->method('importFromFile')->willReturn([
			'success' => true,
			'results' => ['Schema products: created', 'Template post: created'],
			'errors'  => [],
			'summary' => [
				'schemas_created'       => 1,
				'collections_created'   => 0,
				'templates_created'     => 1,
				'objects_created'       => 0,
				'factory_items_created' => 0,
				'total_errors'          => 0,
			],
		]);
		$this->totalcms->method('jumpStartImporter')->willReturn($importer);

		$app     = new Application();
		$command = new JumpStartImportCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('imports a jumpstart file', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-js-import-' . uniqid() . '.json';
		file_put_contents($tmpFile, json_encode(['version' => '1.0.0', 'schemas' => []]));

		$this->tester->execute(['file' => $tmpFile]);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('import complete');
		expect($output)->toContain('schemas_created');
		expect($this->tester->getStatusCode())->toBe(0);
		@unlink($tmpFile);
	});

	it('outputs JSON result with --json', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-js-import-' . uniqid() . '.json';
		file_put_contents($tmpFile, json_encode(['version' => '1.0.0']));

		$this->tester->execute(['file' => $tmpFile, '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data['success'])->toBeTrue();
		expect($data['summary']['schemas_created'])->toBe(1);
		@unlink($tmpFile);
	});

	it('returns error for missing file', function (): void {
		$this->tester->execute(['file' => '/nonexistent/file.json']);
		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('shows errors from import', function (): void {
		$importer = $this->createMock(JumpStartImporter::class);
		$importer->method('importFromFile')->willReturn([
			'success' => false,
			'results' => [],
			'errors'  => ['Schema bad: validation failed'],
			'summary' => ['schemas_created' => 0, 'total_errors' => 1],
		]);
		$totalcms = $this->createMock(TotalCMS::class);
		$totalcms->method('jumpStartImporter')->willReturn($importer);

		$app     = new Application();
		$command = new JumpStartImportCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tmpFile = sys_get_temp_dir() . '/tcms-js-import-' . uniqid() . '.json';
		file_put_contents($tmpFile, '{}');

		$tester->execute(['file' => $tmpFile]);

		expect($tester->getDisplay())->toContain('validation failed');
		@unlink($tmpFile);
	});
});
