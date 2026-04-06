<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionImportCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$fetcher = $this->createMock(CollectionFetcher::class);
	$fetcher->method('collectionExists')->willReturnCallback(
		fn (string $id): bool => $id === 'blog'
	);
	$this->totalcms->method('collectionFetcher')->willReturn($fetcher);
});

it('imports JSON file into collection', function (): void {
	$jsonImporter = $this->createMock(JsonImporter::class);
	$jsonImporter->expects($this->once())
		->method('import')
		->with('blog', $this->isInstanceOf(UploadedFileInterface::class), false)
		->willReturn(5);
	$this->totalcms->method('jsonImporter')->willReturn($jsonImporter);

	$tmpFile = sys_get_temp_dir() . '/tcms-import-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, json_encode([['id' => 'post-1', 'title' => 'Test']]));

	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'blog', 'file' => $tmpFile]);

	expect($tester->getDisplay())->toContain('5 object(s) imported');
	expect($tester->getStatusCode())->toBe(0);
	@unlink($tmpFile);
});

it('imports CSV file into collection', function (): void {
	$csvImporter = $this->createMock(CsvImporter::class);
	$csvImporter->expects($this->once())
		->method('import')
		->with('blog', $this->isInstanceOf(UploadedFileInterface::class), false)
		->willReturn(3);
	$this->totalcms->method('csvImporter')->willReturn($csvImporter);

	$tmpFile = sys_get_temp_dir() . '/tcms-import-test-' . uniqid() . '.csv';
	file_put_contents($tmpFile, "id,title\npost-1,Test\npost-2,Test 2\npost-3,Test 3");

	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'blog', 'file' => $tmpFile]);

	expect($tester->getDisplay())->toContain('3 object(s) imported');
	@unlink($tmpFile);
});

it('auto-detects format from file extension', function (): void {
	$csvImporter = $this->createMock(CsvImporter::class);
	$csvImporter->expects($this->once())->method('import')->willReturn(1);
	$this->totalcms->method('csvImporter')->willReturn($csvImporter);

	$tmpFile = sys_get_temp_dir() . '/tcms-import-test-' . uniqid() . '.csv';
	file_put_contents($tmpFile, "id,title\npost-1,Test");

	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'blog', 'file' => $tmpFile]);

	expect($tester->getStatusCode())->toBe(0);
	@unlink($tmpFile);
});

it('passes --update flag to importer', function (): void {
	$jsonImporter = $this->createMock(JsonImporter::class);
	$jsonImporter->expects($this->once())
		->method('import')
		->with('blog', $this->isInstanceOf(UploadedFileInterface::class), true)
		->willReturn(2);
	$this->totalcms->method('jsonImporter')->willReturn($jsonImporter);

	$tmpFile = sys_get_temp_dir() . '/tcms-import-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, json_encode([['id' => 'post-1']]));

	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'blog', 'file' => $tmpFile, '--update' => true]);

	expect($tester->getDisplay())->toContain('updated');
	@unlink($tmpFile);
});

it('outputs JSON with --json flag', function (): void {
	$jsonImporter = $this->createMock(JsonImporter::class);
	$jsonImporter->method('import')->willReturn(5);
	$this->totalcms->method('jsonImporter')->willReturn($jsonImporter);

	$tmpFile = sys_get_temp_dir() . '/tcms-import-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, json_encode([['id' => 'post-1']]));

	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'blog', 'file' => $tmpFile, '--json' => true]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['success'])->toBeTrue();
	expect($data['imported'])->toBe(5);
	@unlink($tmpFile);
});

it('returns error for missing file', function (): void {
	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'blog', 'file' => '/nonexistent/file.json']);
	expect($tester->getStatusCode())->toBe(1);
});

it('returns error for nonexistent collection', function (): void {
	$tmpFile = sys_get_temp_dir() . '/tcms-import-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, '[]');

	$app     = new Application();
	$command = new CollectionImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['id' => 'nonexistent', 'file' => $tmpFile]);
	expect($tester->getStatusCode())->toBe(1);
	@unlink($tmpFile);
});
