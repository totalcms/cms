<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\DeckImportCommand;
use TotalCMS\Domain\Import\DeckCsvImporter;
use TotalCMS\Domain\Import\DeckJsonImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$objectFetcher = $this->createMock(ObjectFetcher::class);
	$objectFetcher->method('existsObject')->willReturnCallback(
		fn (string $col, string $id): bool => $col === 'invoices' && $id === 'inv-001'
	);
	$this->totalcms->method('objectFetcher')->willReturn($objectFetcher);
});

it('imports JSON deck items', function (): void {
	$importer = $this->createMock(DeckJsonImporter::class);
	$importer->expects($this->once())
		->method('import')
		->with('invoices', 'inv-001', 'items', $this->isInstanceOf(UploadedFileInterface::class), false)
		->willReturn(3);
	$this->totalcms->method('deckJsonImporter')->willReturn($importer);

	$tmpFile = sys_get_temp_dir() . '/tcms-deck-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, json_encode([['id' => 'item-1', 'name' => 'Widget']]));

	$app     = new Application();
	$command = new DeckImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([
		'collection' => 'invoices',
		'object'     => 'inv-001',
		'property'   => 'items',
		'file'       => $tmpFile,
	]);

	expect($tester->getDisplay())->toContain('3 deck item(s) imported');
	expect($tester->getStatusCode())->toBe(0);
	@unlink($tmpFile);
});

it('imports CSV deck items', function (): void {
	$importer = $this->createMock(DeckCsvImporter::class);
	$importer->expects($this->once())->method('import')->willReturn(2);
	$this->totalcms->method('deckCsvImporter')->willReturn($importer);

	$tmpFile = sys_get_temp_dir() . '/tcms-deck-test-' . uniqid() . '.csv';
	file_put_contents($tmpFile, "id,name\nitem-1,Widget\nitem-2,Gadget");

	$app     = new Application();
	$command = new DeckImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([
		'collection' => 'invoices',
		'object'     => 'inv-001',
		'property'   => 'items',
		'file'       => $tmpFile,
	]);

	expect($tester->getDisplay())->toContain('2 deck item(s) imported');
	@unlink($tmpFile);
});

it('outputs JSON with --json', function (): void {
	$importer = $this->createMock(DeckJsonImporter::class);
	$importer->method('import')->willReturn(3);
	$this->totalcms->method('deckJsonImporter')->willReturn($importer);

	$tmpFile = sys_get_temp_dir() . '/tcms-deck-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, '[]');

	$app     = new Application();
	$command = new DeckImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([
		'collection' => 'invoices',
		'object'     => 'inv-001',
		'property'   => 'items',
		'file'       => $tmpFile,
		'--json'     => true,
	]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['success'])->toBeTrue();
	expect($data['imported'])->toBe(3);
	@unlink($tmpFile);
});

it('returns error for nonexistent object', function (): void {
	$tmpFile = sys_get_temp_dir() . '/tcms-deck-test-' . uniqid() . '.json';
	file_put_contents($tmpFile, '[]');

	$app     = new Application();
	$command = new DeckImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([
		'collection' => 'invoices',
		'object'     => 'nonexistent',
		'property'   => 'items',
		'file'       => $tmpFile,
	]);

	expect($tester->getStatusCode())->toBe(1);
	@unlink($tmpFile);
});

it('returns error for missing file', function (): void {
	$app     = new Application();
	$command = new DeckImportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([
		'collection' => 'invoices',
		'object'     => 'inv-001',
		'property'   => 'items',
		'file'       => '/nonexistent/file.json',
	]);

	expect($tester->getStatusCode())->toBe(1);
});
