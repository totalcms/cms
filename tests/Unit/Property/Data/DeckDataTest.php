<?php

use TotalCMS\Domain\Property\Data\DeckData;

describe('DeckData', function (): void {
	test('DeckData → creates with empty deck', function (): void {
		$deck = new DeckData();

		expect($deck->deck)->toBe([]);
		expect($deck->settings)->toBe([]);
		expect($deck->count())->toBe(0);
	});

	test('DeckData → creates with settings', function (): void {
		$settings = ['maxItems' => 10, 'sortable' => true];
		$deck     = new DeckData([], $settings);

		expect($deck->settings)->toBe($settings);
	});

	test('DeckData → creates with valid deck data', function (): void {
		$deckData = [
			'item1' => ['name' => 'First Item', 'value' => 100],
			'item2' => ['name' => 'Second Item', 'value' => 200],
		];

		$deck = new DeckData($deckData);

		expect($deck->deck)->toBe($deckData);
		expect($deck->count())->toBe(2);
	});

	test('DeckData → validates deck item names with alphanumeric and underscores', function (): void {
		$validNames = [
			'item1'       => ['value' => 1],
			'ITEM_2'      => ['value' => 2],
			'item_3_test' => ['value' => 3],
			'123'         => ['value' => 4],
		];

		$deck = new DeckData($validNames);
		expect($deck->count())->toBe(4);
	});

	test('DeckData → throws exception for invalid deck item names', function (): void {
		$invalidNames = [
			'item-1' => ['value' => 1], // Dash not allowed
		];

		expect(fn (): DeckData => new DeckData($invalidNames))
			->toThrow(InvalidArgumentException::class, 'Deck must be a dictionary of named objects');
	});

	test('DeckData → throws exception for indexed array (must be associative)', function (): void {
		$indexedArray = [
			['name' => 'First Item'],
			['name' => 'Second Item'],
		];

		expect(fn (): DeckData => new DeckData($indexedArray))
			->toThrow(InvalidArgumentException::class, 'Deck must be a dictionary of named objects');
	});

	test('DeckData → throws exception for non-array items', function (): void {
		$invalidItems = [
			'item1' => 'string value', // Must be array
		];

		expect(fn (): DeckData => new DeckData($invalidItems))
			->toThrow(InvalidArgumentException::class, 'Deck must be a dictionary of named objects');
	});

	test('DeckData → validates id field matches dictionary key', function (): void {
		$validWithId = [
			'item1' => ['id' => 'item1', 'name' => 'First Item'],
			'item2' => ['id' => 'item2', 'name' => 'Second Item'],
		];

		$deck = new DeckData($validWithId);
		expect($deck->count())->toBe(2);
	});

	test('DeckData → throws exception when id field does not match key', function (): void {
		$invalidId = [
			'item1' => ['id' => 'different_id', 'name' => 'Item'],
		];

		expect(fn (): DeckData => new DeckData($invalidId))
			->toThrow(InvalidArgumentException::class, 'Deck must be a dictionary of named objects');
	});

	test('DeckData → handles numeric keys', function (): void {
		$numericKeys = [
			123 => ['name' => 'Numeric Item'],
			456 => ['id' => '456', 'name' => 'With ID'], // ID as string matches key converted to string
		];

		$deck = new DeckData($numericKeys);
		expect($deck->count())->toBe(2);
		expect($deck->hasItem('123'))->toBe(true);
		expect($deck->hasItem('456'))->toBe(true);
	});

	test('DeckData → getItem returns item by name', function (): void {
		$deckData = [
			'item1' => ['name' => 'First Item', 'value' => 100],
			'item2' => ['name' => 'Second Item', 'value' => 200],
		];

		$deck = new DeckData($deckData);

		expect($deck->getItem('item1'))->toBe(['name' => 'First Item', 'value' => 100]);
		expect($deck->getItem('item2'))->toBe(['name' => 'Second Item', 'value' => 200]);
		expect($deck->getItem('nonexistent'))->toBe(null);
	});

	test('DeckData → setItem adds new items', function (): void {
		$deck = new DeckData();

		$deck->setItem('new_item', ['name' => 'New Item', 'value' => 300]);

		expect($deck->count())->toBe(1);
		expect($deck->getItem('new_item'))->toBe(['name' => 'New Item', 'value' => 300]);
	});

	test('DeckData → setItem updates existing items', function (): void {
		$deckData = ['item1' => ['name' => 'Original', 'value' => 100]];
		$deck     = new DeckData($deckData);

		$deck->setItem('item1', ['name' => 'Updated', 'value' => 999]);

		expect($deck->getItem('item1'))->toBe(['name' => 'Updated', 'value' => 999]);
	});

	test('DeckData → setItem validates item name', function (): void {
		$deck = new DeckData();

		expect(fn () => $deck->setItem('invalid-name', ['value' => 1]))
			->toThrow(InvalidArgumentException::class, 'Deck item name must contain only alphanumeric characters and underscores');
	});

	test('DeckData → setItem validates id field matches name', function (): void {
		$deck = new DeckData();

		// Valid case
		$deck->setItem('item1', ['id' => 'item1', 'value' => 1]);
		expect($deck->count())->toBe(1);

		// Invalid case
		expect(fn () => $deck->setItem('item2', ['id' => 'wrong_id', 'value' => 2]))
			->toThrow(InvalidArgumentException::class, "Deck item 'id' field ('wrong_id') must match the dictionary key ('item2')");
	});

	test('DeckData → removeItem removes items', function (): void {
		$deckData = [
			'item1' => ['value' => 1],
			'item2' => ['value' => 2],
		];
		$deck = new DeckData($deckData);

		$deck->removeItem('item1');

		expect($deck->count())->toBe(1);
		expect($deck->hasItem('item1'))->toBe(false);
		expect($deck->hasItem('item2'))->toBe(true);
	});

	test('DeckData → removeItem handles nonexistent items gracefully', function (): void {
		$deck = new DeckData(['item1' => ['value' => 1]]);

		// Should not throw
		$deck->removeItem('nonexistent');

		expect($deck->count())->toBe(1);
	});

	test('DeckData → getItemNames returns all item names', function (): void {
		$deckData = [
			'first'  => ['value' => 1],
			'second' => ['value' => 2],
			'third'  => ['value' => 3],
		];
		$deck = new DeckData($deckData);

		$names = $deck->getItemNames();

		expect($names)->toBe(['first', 'second', 'third']);
	});

	test('DeckData → getItemNames returns empty array for empty deck', function (): void {
		$deck = new DeckData();

		expect($deck->getItemNames())->toBe([]);
	});

	test('DeckData → hasItem checks item existence', function (): void {
		$deckData = ['existing' => ['value' => 1]];
		$deck     = new DeckData($deckData);

		expect($deck->hasItem('existing'))->toBe(true);
		expect($deck->hasItem('nonexistent'))->toBe(false);
	});

	test('DeckData → transform returns empty array for empty deck', function (): void {
		$deck = new DeckData();

		$result = $deck->transform();

		expect($result)->toBe([]);
	});

	test('DeckData → transform returns deck array for non-empty deck', function (): void {
		$deckData = ['item1' => ['value' => 1]];
		$deck     = new DeckData($deckData);

		$result = $deck->transform();

		expect($result)->toBe($deckData);
	});

	test('DeckData → __toString converts to JSON', function (): void {
		$deckData = [
			'item1' => ['name' => 'First', 'value' => 100],
			'item2' => ['name' => 'Second', 'value' => 200],
		];
		$deck = new DeckData($deckData);

		$json    = (string)$deck;
		$decoded = json_decode($json, true);

		expect($decoded)->toBe($deckData);
	});

	test('DeckData → __toString returns empty array JSON for empty deck', function (): void {
		$deck = new DeckData();

		$json = (string)$deck;

		expect($json)->toBe('[]');
		expect(json_decode($json, true))->toBe([]);
	});

	test('DeckData → handles complex nested data structures', function (): void {
		$complexData = [
			'user1' => [
				'name' => 'John Doe',
				'meta' => ['age' => 30, 'active' => true],
				'tags' => ['admin', 'developer'],
			],
			'user2' => [
				'name' => 'Jane Smith',
				'meta' => ['age' => 25, 'active' => false],
				'tags' => ['user'],
			],
		];

		$deck = new DeckData($complexData);

		expect($deck->count())->toBe(2);
		expect($deck->getItem('user1')['meta']['age'])->toBe(30);
		expect($deck->getItem('user2')['tags'])->toBe(['user']);
	});

	test('DeckData → allows items without id field', function (): void {
		$dataWithoutId = [
			'config' => ['setting1' => 'value1', 'setting2' => 'value2'],
			'state'  => ['initialized' => true, 'count' => 5],
		];

		$deck = new DeckData($dataWithoutId);

		expect($deck->count())->toBe(2);
		expect($deck->getItem('config'))->toBe(['setting1' => 'value1', 'setting2' => 'value2']);
	});
});
