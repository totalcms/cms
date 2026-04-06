<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Illuminate\Support\Collection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\ObjectGetCommand;
use TotalCMS\CLI\Command\ObjectListCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$collectionFetcher = $this->createMock(CollectionFetcher::class);
	$collectionFetcher->method('collectionExists')->willReturnCallback(
		fn (string $id): bool => $id === 'blog'
	);
	$this->totalcms->method('collectionFetcher')->willReturn($collectionFetcher);
});

describe('object:list', function (): void {
	beforeEach(function (): void {
		$indexData = new IndexData([
			['id' => 'post-1', 'title' => 'First'],
			['id' => 'post-2', 'title' => 'Second'],
			['id' => 'post-3', 'title' => 'Third'],
		]);

		$indexReader = $this->createMock(IndexReader::class);
		$indexReader->method('fetchIndex')->willReturn($indexData);
		$this->totalcms->method('indexReader')->willReturn($indexReader);

		$app     = new Application();
		$command = new ObjectListCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('lists object IDs', function (): void {
		$this->tester->execute(['collection' => 'blog']);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('post-1');
		expect($output)->toContain('post-2');
		expect($output)->toContain('post-3');
		expect($output)->toContain('3 object(s)');
	});

	it('outputs JSON array with --json', function (): void {
		$this->tester->execute(['collection' => 'blog', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toBe(['post-1', 'post-2', 'post-3']);
	});

	it('respects --limit', function (): void {
		$this->tester->execute(['collection' => 'blog', '--limit' => '2', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(2);
	});

	it('respects --offset', function (): void {
		$this->tester->execute(['collection' => 'blog', '--offset' => '1', '--limit' => '2', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(2);
		expect($data[0])->toBe('post-2');
	});

	it('returns error for nonexistent collection', function (): void {
		$this->tester->execute(['collection' => 'nonexistent']);

		expect($this->tester->getStatusCode())->toBe(1);
	});
});

describe('object:get', function (): void {
	beforeEach(function (): void {
		$object = $this->createMock(ObjectData::class);
		$object->id = 'post-1';
		$object->method('toArray')->willReturn([
			'id'      => 'post-1',
			'title'   => 'First Post',
			'draft'   => false,
			'content' => 'Hello world',
		]);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('existsObject')->willReturnCallback(
			fn (string $col, string $id): bool => $col === 'blog' && $id === 'post-1'
		);
		$objectFetcher->method('fetchObject')->willReturn($object);
		$this->totalcms->method('objectFetcher')->willReturn($objectFetcher);

		$app     = new Application();
		$command = new ObjectGetCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('shows object details', function (): void {
		$this->tester->execute(['collection' => 'blog', 'id' => 'post-1']);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('Object: post-1');
	});

	it('outputs JSON with --json', function (): void {
		$this->tester->execute(['collection' => 'blog', 'id' => 'post-1', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toBeArray();
		expect($data['id'])->toBe('post-1');
	});

	it('returns error for nonexistent object', function (): void {
		$this->tester->execute(['collection' => 'blog', 'id' => 'nonexistent']);

		expect($this->tester->getStatusCode())->toBe(1);
	});
});
