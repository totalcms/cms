<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\AutogenService;
use TotalCMS\Domain\Object\Service\CalcService;
use TotalCMS\Domain\Property\Service\DeckItemFactory;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

describe('DeckItemFactory', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher    = $this->createMock(SchemaFetcher::class);
		$this->autogenIdService = $this->createMock(AutogenIdService::class);
		$this->autogenService   = $this->createMock(AutogenService::class);
		$this->calcService      = $this->createMock(CalcService::class);

		$this->factory = new DeckItemFactory(
			$this->schemaFetcher,
			$this->autogenIdService,
			$this->autogenService,
			$this->calcService,
		);

		// Collection-level schema with a deck property pointing at a deck schema
		$collectionSchema             = new SchemaData();
		$collectionSchema->properties = [
			'comments' => [
				'field'   => 'deck',
				'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json',
			],
		];
		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->willReturn($collectionSchema);
	});

	function makeDeckSchema(array $properties): SchemaData
	{
		$s             = new SchemaData();
		$s->properties = $properties;

		return $s;
	}

	// --- generateIdIfNeeded ---

	test('generateIdIfNeeded returns empty when the deck schema has no id autogen', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'id' => ['field' => 'id'],
		]));

		expect($this->factory->generateIdIfNeeded('blog', 'comments', ['body' => 'x']))->toBe('');
	});

	test('generateIdIfNeeded invokes autogenIdService and converts hyphens to underscores', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'id' => ['field' => 'id', 'settings' => ['autogen' => '${uuid}']],
		]));

		$this->autogenIdService
			->expects($this->once())
			->method('generateId')
			->with('${uuid}', 'blog', ['body' => 'x'])
			->willReturn('new-item-abc');

		// Hyphens → underscores so the id is a valid Twig dot-notation key
		expect($this->factory->generateIdIfNeeded('blog', 'comments', ['body' => 'x']))->toBe('new_item_abc');
	});

	test('generateIdIfNeeded returns empty when the property is not a deck', function (): void {
		expect($this->factory->generateIdIfNeeded('blog', 'nonexistent', []))->toBe('');
	});

	test('generateIdIfNeeded swallows exceptions from autogen and returns empty', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'id' => ['field' => 'id', 'settings' => ['autogen' => '${uuid}']],
		]));
		$this->autogenIdService
			->method('generateId')
			->willThrowException(new RuntimeException('boom'));

		expect($this->factory->generateIdIfNeeded('blog', 'comments', []))->toBe('');
	});

	// --- prepareItemData: applyAutogenFields ---

	test('prepareItemData runs autogen for empty non-ID fields with autogen settings', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'id'   => ['field' => 'id'],
			'slug' => ['field' => 'text', 'settings' => ['autogen' => '${body}']],
			'body' => ['field' => 'text'],
		]));

		$this->autogenService
			->expects($this->once())
			->method('generate')
			->with('${body}', 'blog', $this->anything())
			->willReturn('hello-world');

		$result = $this->factory->prepareItemData('blog', 'comments', ['body' => 'Hello World']);

		expect($result['slug'])->toBe('hello-world');
	});

	test('prepareItemData leaves autogen fields alone when already populated', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'id'   => ['field' => 'id'],
			'slug' => ['field' => 'text', 'settings' => ['autogen' => '${body}']],
			'body' => ['field' => 'text'],
		]));

		$this->autogenService->expects($this->never())->method('generate');

		$result = $this->factory->prepareItemData('blog', 'comments', [
			'body' => 'hi',
			'slug' => 'manual-slug',
		]);

		expect($result['slug'])->toBe('manual-slug');
	});

	test('prepareItemData skips id autogen during the non-id pass', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'id' => ['field' => 'id', 'settings' => ['autogen' => '${uuid}']],
		]));

		$this->autogenService->expects($this->never())->method('generate');

		$result = $this->factory->prepareItemData('blog', 'comments', ['body' => 'x']);

		expect($result)->toBe(['body' => 'x']);
	});

	// --- prepareItemData: applyCalcFields ---

	test('prepareItemData evaluates calc expressions and clamps', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'total' => ['field' => 'number', 'settings' => ['calc' => 'price * qty']],
		]));

		$this->calcService
			->method('evaluate')
			->with('price * qty', $this->anything())
			->willReturn(42.0);
		$this->calcService
			->method('clampValue')
			->willReturnArgument(0);

		$result = $this->factory->prepareItemData('blog', 'comments', ['price' => 6, 'qty' => 7]);

		expect($result['total'])->toBe(42.0);
	});

	test('prepareItemData leaves calc field alone if evaluation throws RuntimeException', function (): void {
		$this->schemaFetcher->method('fetchSchema')->willReturn(makeDeckSchema([
			'total' => ['field' => 'number', 'settings' => ['calc' => 'bogus']],
		]));

		$this->calcService
			->method('evaluate')
			->willThrowException(new RuntimeException('invalid expression'));
		$this->calcService->expects($this->never())->method('clampValue');

		$result = $this->factory->prepareItemData('blog', 'comments', ['total' => 'preset', 'price' => 1]);

		// Pre-existing value is preserved because the exception is swallowed
		expect($result['total'])->toBe('preset');
	});

	test('prepareItemData returns data unchanged when the deck schema cannot be fetched', function (): void {
		// A property with no deckref → fetchDeckSchema returns null → no-op
		$collectionSchema             = new SchemaData();
		$collectionSchema->properties = [
			'orphan' => ['field' => 'deck'], // no deckref
		];
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($collectionSchema);
		$factory = new DeckItemFactory(
			$this->schemaFetcher,
			$this->autogenIdService,
			$this->autogenService,
			$this->calcService,
		);

		$result = $factory->prepareItemData('blog', 'orphan', ['body' => 'x']);

		expect($result)->toBe(['body' => 'x']);
	});
});
