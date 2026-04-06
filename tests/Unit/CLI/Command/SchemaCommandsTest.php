<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\SchemaGetCommand;
use TotalCMS\CLI\Command\SchemaListCommand;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$this->schema1             = new SchemaData();
	$this->schema1->id         = 'blog';
	$this->schema1->category   = 'Content';
	$this->schema1->description = 'Blog schema';

	$this->schema2             = new SchemaData();
	$this->schema2->id         = 'products';
	$this->schema2->category   = 'Commerce';
	$this->schema2->description = 'Products schema';

	$this->schemaLister = $this->createMock(SchemaLister::class);
	$this->schemaLister->method('listAllSchemas')->willReturn([$this->schema1, $this->schema2]);
	$this->schemaLister->method('listCustomSchemas')->willReturn([$this->schema2]);
	$this->schemaLister->method('listReservedSchemas')->willReturn([$this->schema1]);
	$this->totalcms->method('schemaLister')->willReturn($this->schemaLister);

	$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
	$this->schemaFetcher->method('schemaExists')->willReturnCallback(
		fn (string $id): bool => in_array($id, ['blog', 'products'], true)
	);
	$this->schemaFetcher->method('fetchSchema')->willReturnCallback(
		fn (string $id): SchemaData => $id === 'blog' ? $this->schema1 : $this->schema2
	);
	$this->totalcms->method('schemaFetcher')->willReturn($this->schemaFetcher);
});

describe('schema:list', function (): void {
	beforeEach(function (): void {
		$app     = new Application();
		$command = new SchemaListCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('lists all schemas in human format', function (): void {
		$this->tester->execute([]);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('blog');
		expect($output)->toContain('products');
	});

	it('outputs JSON array with --json', function (): void {
		$this->tester->execute(['--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toBeArray();
		expect($data)->toHaveCount(2);
		expect($data[0]['id'])->toBe('blog');
	});

	it('filters by --custom flag', function (): void {
		$this->tester->execute(['--custom' => true, '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(1);
		expect($data[0]['id'])->toBe('products');
	});

	it('filters by --reserved flag', function (): void {
		$this->tester->execute(['--reserved' => true, '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(1);
		expect($data[0]['id'])->toBe('blog');
	});

	it('filters by --category', function (): void {
		$this->tester->execute(['--category' => 'Commerce', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(1);
		expect($data[0]['id'])->toBe('products');
	});
});

describe('schema:get', function (): void {
	beforeEach(function (): void {
		$app     = new Application();
		$command = new SchemaGetCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('shows schema details in human format', function (): void {
		$this->tester->execute(['id' => 'blog']);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('Schema: blog');
		expect($output)->toContain('Blog schema');
	});

	it('outputs full schema JSON with --json', function (): void {
		$this->tester->execute(['id' => 'blog', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toBeArray();
		expect($data['id'])->toBe('blog');
	});

	it('returns error for nonexistent schema', function (): void {
		$this->tester->execute(['id' => 'nonexistent']);

		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('returns JSON error for nonexistent schema with --json', function (): void {
		$this->tester->execute(['id' => 'nonexistent', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveKey('error');
		expect($this->tester->getStatusCode())->toBe(1);
	});
});
