<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\SchemaLintCommand;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLinter;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$clean      = new SchemaData();
	$clean->id  = 'clean';
	$broken     = new SchemaData();
	$broken->id = 'broken';

	$this->schemaLister = $this->createMock(SchemaLister::class);
	$this->schemaLister->method('listCustomSchemas')->willReturn([$clean, $broken]);
	$this->totalcms->method('schemaLister')->willReturn($this->schemaLister);

	$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
	$this->schemaFetcher->method('schemaExists')->willReturnCallback(
		fn (string $id): bool => in_array($id, ['clean', 'broken', 'warned'], true)
	);
	$this->totalcms->method('schemaFetcher')->willReturn($this->schemaFetcher);

	$this->linter = $this->createMock(SchemaLinter::class);
	$this->linter->method('lint')->willReturnCallback(fn (string $id): array => match ($id) {
		'broken' => [
			'errors'   => ["No 'id' property is defined (own or inherited). Every schema needs one."],
			'warnings' => ["Property 'title' has no help text — help feeds the MCP tool catalog AI agents read."],
		],
		'warned' => ['errors' => [], 'warnings' => ['Schema has no description.']],
		default  => ['errors' => [], 'warnings' => []],
	});
	$this->totalcms->method('schemaLinter')->willReturn($this->linter);

	$app     = new Application();
	$command = new SchemaLintCommand($this->totalcms);
	$app->addCommand($command);
	$this->tester = new CommandTester($command);
});

describe('schema:lint', function (): void {
	it('lints all custom schemas by default and fails on errors', function (): void {
		$this->tester->execute([]);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('✓ clean');
		expect($output)->toContain("No 'id' property is defined");
		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('passes when a single schema is clean', function (): void {
		$this->tester->execute(['id' => 'clean']);

		expect($this->tester->getStatusCode())->toBe(0);
	});

	it('does not fail on warnings without --strict', function (): void {
		$this->tester->execute(['id' => 'warned']);

		expect($this->tester->getStatusCode())->toBe(0);
	});

	it('fails on warnings with --strict', function (): void {
		$this->tester->execute(['id' => 'warned', '--strict' => true]);

		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('errors on a missing schema id', function (): void {
		$this->tester->execute(['id' => 'nope']);

		expect($this->tester->getStatusCode())->toBe(1);
		expect($this->tester->getDisplay())->toContain('Schema not found: nope');
	});

	it('emits a machine-readable report with --json', function (): void {
		$this->tester->execute(['--json' => true]);

		$data = json_decode((string)$this->tester->getDisplay(), true);
		expect($data)->toHaveKeys(['schemas', 'errors', 'warnings', 'passed']);
		expect($data['errors'])->toBe(1);
		expect($data['warnings'])->toBe(1);
		expect($data['passed'])->toBeFalse();
		expect($data['schemas']['broken']['errors'])->toHaveCount(1);
		expect($data['schemas']['clean']['errors'])->toBeEmpty();
	});
});
