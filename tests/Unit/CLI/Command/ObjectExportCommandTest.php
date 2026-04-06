<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\ObjectExportCommand;
use TotalCMS\Domain\Export\Service\ObjectZipper;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$object = $this->createMock(ObjectData::class);
	$object->id = 'post-1';
	$object->method('toArray')->willReturn([
		'id'    => 'post-1',
		'title' => 'Test Post',
		'draft' => false,
	]);

	$objectFetcher = $this->createMock(ObjectFetcher::class);
	$objectFetcher->method('existsObject')->willReturnCallback(
		fn (string $col, string $id): bool => $col === 'blog' && $id === 'post-1'
	);
	$objectFetcher->method('fetchObject')->willReturn($object);
	$this->totalcms->method('objectFetcher')->willReturn($objectFetcher);
});

it('exports object as JSON to stdout', function (): void {
	$app     = new Application();
	$command = new ObjectExportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['collection' => 'blog', 'id' => 'post-1']);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['id'])->toBe('post-1');
	expect($data['title'])->toBe('Test Post');
});

it('exports object JSON to file with --output', function (): void {
	$tmpFile = sys_get_temp_dir() . '/tcms-obj-export-' . uniqid() . '.json';

	$app     = new Application();
	$command = new ObjectExportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['collection' => 'blog', 'id' => 'post-1', '--output' => $tmpFile]);

	expect(file_exists($tmpFile))->toBeTrue();
	$data = json_decode((string) file_get_contents($tmpFile), true);
	expect($data['id'])->toBe('post-1');
	@unlink($tmpFile);
});

it('exports object as zip', function (): void {
	$tmpZip = sys_get_temp_dir() . '/tcms-obj-zip-src-' . uniqid() . '.zip';
	// Create a minimal valid zip
	$zip = new \ZipArchive();
	$zip->open($tmpZip, \ZipArchive::CREATE);
	$zip->addFromString('post-1.json', '{"id":"post-1"}');
	$zip->close();

	$zipper = $this->createMock(ObjectZipper::class);
	$zipper->method('createObjectZip')->willReturn($tmpZip);
	$zipper->method('getZipFilename')->willReturn('blog--post-1.zip');
	$this->totalcms->method('objectZipper')->willReturn($zipper);

	$destZip = sys_get_temp_dir() . '/tcms-obj-zip-dest-' . uniqid() . '.zip';

	$app     = new Application();
	$command = new ObjectExportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['collection' => 'blog', 'id' => 'post-1', '--format' => 'zip', '--output' => $destZip]);

	expect(file_exists($destZip))->toBeTrue();
	expect($tester->getDisplay())->toContain('Exported to');
	@unlink($destZip);
});

it('returns error for nonexistent object', function (): void {
	$app     = new Application();
	$command = new ObjectExportCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute(['collection' => 'blog', 'id' => 'nonexistent']);
	expect($tester->getStatusCode())->toBe(1);
});
