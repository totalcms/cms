<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\ObjectCreateCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
	$this->collectionFetcher->method('collectionExists')->willReturnCallback(
		fn (string $id): bool => $id === 'blog'
	);
	$this->totalcms->method('collectionFetcher')->willReturn($this->collectionFetcher);

	$this->objectFetcher = $this->createMock(ObjectFetcher::class);
	$this->objectFetcher->method('existsObject')->willReturnCallback(
		fn (string $collection, string $id): bool => $id === 'taken'
	);
	$this->totalcms->method('objectFetcher')->willReturn($this->objectFetcher);

	$this->objectSaver = $this->createMock(ObjectSaver::class);
	$this->objectSaver->method('saveObject')->willReturnCallback(
		// ObjectData wants PropertyData values; an empty property set keeps the
		// mock honest enough for toArray() while exercising the command path.
		fn (string $collection, array $data): ObjectData => new ObjectData((string)($data['id'] ?? 'generated'), [])
	);
	$this->totalcms->method('objectSaver')->willReturn($this->objectSaver);

	$app     = new Application();
	$command = new ObjectCreateCommand($this->totalcms);
	$app->addCommand($command);
	$this->tester = new CommandTester($command);

	$this->tmpFile = tempnam(sys_get_temp_dir(), 'tcms-object-create-test');
});

afterEach(function (): void {
	if (is_string($this->tmpFile) && file_exists($this->tmpFile)) {
		unlink($this->tmpFile);
	}
});

describe('object:create', function (): void {
	it('creates an object from a JSON file', function (): void {
		file_put_contents((string)$this->tmpFile, '{"id":"hello","title":"Hello"}');

		$this->tester->execute(['collection' => 'blog', 'file' => $this->tmpFile]);

		expect($this->tester->getStatusCode())->toBe(0);
		expect($this->tester->getDisplay())->toContain("Object 'hello' created in collection 'blog'.");
	});

	it('echoes the saved object with --json', function (): void {
		file_put_contents((string)$this->tmpFile, '{"id":"hello","title":"Hello"}');

		$this->tester->execute(['collection' => 'blog', 'file' => $this->tmpFile, '--json' => true]);

		$data = json_decode((string)$this->tester->getDisplay(), true);
		expect($data['id'])->toBe('hello');
		expect($this->tester->getStatusCode())->toBe(0);
	});

	it('errors when the collection does not exist', function (): void {
		file_put_contents((string)$this->tmpFile, '{"id":"hello"}');

		$this->tester->execute(['collection' => 'nope', 'file' => $this->tmpFile]);

		expect($this->tester->getStatusCode())->toBe(1);
		expect($this->tester->getDisplay())->toContain("Collection 'nope' not found.");
	});

	it('errors when the file does not exist', function (): void {
		$this->tester->execute(['collection' => 'blog', 'file' => '/definitely/not/here.json']);

		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('rejects a JSON array with a pointer to collection:import', function (): void {
		file_put_contents((string)$this->tmpFile, '[{"id":"hello"}]');

		$this->tester->execute(['collection' => 'blog', 'file' => $this->tmpFile]);

		expect($this->tester->getStatusCode())->toBe(1);
		expect($this->tester->getDisplay())->toContain('collection:import');
	});

	it('refuses to overwrite an existing object', function (): void {
		file_put_contents((string)$this->tmpFile, '{"id":"taken","title":"Dupe"}');

		$this->tester->execute(['collection' => 'blog', 'file' => $this->tmpFile]);

		expect($this->tester->getStatusCode())->toBe(1);
		expect($this->tester->getDisplay())->toContain("already exists");
	});

	it('surfaces saver failures as command errors', function (): void {
		$saver = $this->createMock(ObjectSaver::class);
		$saver->method('saveObject')->willThrowException(new \DomainException('Schema Validation Failed.'));
		$totalcms = $this->createMock(TotalCMS::class);
		$totalcms->method('collectionFetcher')->willReturn($this->collectionFetcher);
		$totalcms->method('objectFetcher')->willReturn($this->objectFetcher);
		$totalcms->method('objectSaver')->willReturn($saver);

		$command = new ObjectCreateCommand($totalcms);
		(new Application())->addCommand($command);
		$tester = new CommandTester($command);

		file_put_contents((string)$this->tmpFile, '{"id":"hello"}');
		$tester->execute(['collection' => 'blog', 'file' => $this->tmpFile]);

		expect($tester->getStatusCode())->toBe(1);
		expect($tester->getDisplay())->toContain('Schema Validation Failed.');
	});
});
