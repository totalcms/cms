<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Service\DeckItemFetcher;

/**
 * Coverage for the deck-item read service used by
 * `GET /collections/{c}/{id}/{p}/deck/{itemId}` and the index listing.
 */
describe('DeckItemFetcher', function (): void {
	beforeEach(function (): void {
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->fetcher       = new DeckItemFetcher($this->objectFetcher);
	});

	function makeObjectWithDeck(array $deckData): ObjectData
	{
		return new ObjectData('post-1', [
			'comments' => new DeckData($deckData),
		]);
	}

	test('fetchDeckItem returns the item data by ID', function (): void {
		$object = makeObjectWithDeck([
			'c_1' => ['id' => 'c_1', 'author' => 'alice', 'body' => 'first'],
			'c_2' => ['id' => 'c_2', 'author' => 'bob', 'body' => 'second'],
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$item = $this->fetcher->fetchDeckItem('blog', 'post-1', 'comments', 'c_2');

		expect($item)->toBe(['id' => 'c_2', 'author' => 'bob', 'body' => 'second']);
	});

	test('fetchDeckItem returns null when the itemId is missing', function (): void {
		$object = makeObjectWithDeck(['c_1' => ['id' => 'c_1']]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect($this->fetcher->fetchDeckItem('blog', 'post-1', 'comments', 'missing'))->toBeNull();
	});

	test('fetchDeckItem throws when the property is not a deck', function (): void {
		$object = new ObjectData('post-1', ['title' => new StringData('Hello')]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect(fn () => $this->fetcher->fetchDeckItem('blog', 'post-1', 'title', 'anything'))
			->toThrow(\InvalidArgumentException::class, "Property 'title' is not a deck property");
	});

	test('fetchAllDeckItems returns the whole deck array', function (): void {
		$deck   = [
			'a' => ['id' => 'a', 'body' => 'A'],
			'b' => ['id' => 'b', 'body' => 'B'],
		];
		$object = makeObjectWithDeck($deck);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect($this->fetcher->fetchAllDeckItems('blog', 'post-1', 'comments'))->toBe($deck);
	});

	test('fetchAllDeckItems throws when the property is not a deck', function (): void {
		$object = new ObjectData('post-1', ['title' => new StringData('Hello')]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect(fn () => $this->fetcher->fetchAllDeckItems('blog', 'post-1', 'title'))
			->toThrow(\InvalidArgumentException::class);
	});

	test('fetchDeckItemIds returns just the keys, preserving order', function (): void {
		$object = makeObjectWithDeck([
			'first'  => ['id' => 'first'],
			'second' => ['id' => 'second'],
			'third'  => ['id' => 'third'],
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect($this->fetcher->fetchDeckItemIds('blog', 'post-1', 'comments'))->toBe(['first', 'second', 'third']);
	});

	test('fetchDeckItemIds throws when the property is not a deck', function (): void {
		$object = new ObjectData('post-1', ['title' => new StringData('Hello')]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect(fn () => $this->fetcher->fetchDeckItemIds('blog', 'post-1', 'title'))
			->toThrow(\InvalidArgumentException::class);
	});
});
