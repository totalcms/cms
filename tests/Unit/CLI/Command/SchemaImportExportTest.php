<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\SchemaExportCommand;
use TotalCMS\CLI\Command\SchemaImportCommand;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\TotalCMS;

describe('schema:export', function (): void {
	beforeEach(function (): void {
		$this->totalcms = $this->createMock(TotalCMS::class);

		$schema              = new SchemaData();
		$schema->id          = 'products';
		$schema->description = 'Products schema';

		$fetcher = $this->createMock(SchemaFetcher::class);
		$fetcher->method('schemaExists')->willReturnCallback(
			fn (string $id): bool => $id === 'products'
		);
		$fetcher->method('fetchSchema')->willReturn($schema);
		$this->totalcms->method('schemaFetcher')->willReturn($fetcher);

		$app     = new Application();
		$command = new SchemaExportCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('exports schema JSON to stdout', function (): void {
		$this->tester->execute(['id' => 'products']);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toBeArray();
		expect($data['id'])->toBe('products');
	});

	it('exports schema to file with --output', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-schema-export-test-' . uniqid() . '.json';

		$this->tester->execute(['id' => 'products', '--output' => $tmpFile]);

		expect(file_exists($tmpFile))->toBeTrue();
		$data = json_decode((string)file_get_contents($tmpFile), true);
		expect($data['id'])->toBe('products');
		@unlink($tmpFile);
	});

	it('returns error for nonexistent schema', function (): void {
		$this->tester->execute(['id' => 'nonexistent']);
		expect($this->tester->getStatusCode())->toBe(1);
	});
});

describe('schema:import', function (): void {
	beforeEach(function (): void {
		$this->totalcms = $this->createMock(TotalCMS::class);

		$schema     = new SchemaData();
		$schema->id = 'new-schema';

		$saver = $this->createMock(SchemaSaver::class);
		$saver->method('saveSchema')->willReturn($schema);
		$this->totalcms->method('schemaSaver')->willReturn($saver);

		$app     = new Application();
		$command = new SchemaImportCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('imports a schema from JSON file', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-schema-import-test-' . uniqid() . '.json';
		file_put_contents($tmpFile, json_encode([
			'id'         => 'new-schema',
			'properties' => ['name' => ['type' => 'string', 'field' => 'text']],
		]));

		$this->tester->execute(['file' => $tmpFile]);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('new-schema');
		expect($this->tester->getStatusCode())->toBe(0);
		@unlink($tmpFile);
	});

	it('outputs JSON with --json', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-schema-import-test-' . uniqid() . '.json';
		file_put_contents($tmpFile, json_encode([
			'id'         => 'new-schema',
			'properties' => ['name' => ['type' => 'string']],
		]));

		$this->tester->execute(['file' => $tmpFile, '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data['success'])->toBeTrue();
		expect($data['id'])->toBe('new-schema');
		@unlink($tmpFile);
	});

	it('returns error for missing file', function (): void {
		$this->tester->execute(['file' => '/nonexistent/file.json']);
		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('returns error for invalid JSON', function (): void {
		$tmpFile = sys_get_temp_dir() . '/tcms-schema-bad-' . uniqid() . '.json';
		file_put_contents($tmpFile, 'not json');

		$this->tester->execute(['file' => $tmpFile]);

		expect($this->tester->getStatusCode())->toBe(1);
		@unlink($tmpFile);
	});
});
