<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Service\DeckItemUpdater;
use TotalCMS\Domain\Property\Service\DeckItemValidator;
use TotalCMS\Domain\Property\Service\PropertyFactory;

describe('DeckItemUpdater', function (): void {
	beforeEach(function (): void {
		$this->objectFetcher   = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater   = $this->createMock(ObjectUpdater::class);
		$this->propertyFactory = $this->createMock(PropertyFactory::class);
		$this->validator       = $this->createMock(DeckItemValidator::class);

		$this->updater = new DeckItemUpdater(
			$this->objectFetcher,
			$this->objectUpdater,
			$this->propertyFactory,
			$this->validator,
		);

		$this->propertyFactory
			->method('processIndividualDeckItem')
			->willReturnArgument(2);
	});

	test('updateDeckItem overwrites an existing item with processed/validated data', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData([
				'target' => ['id' => 'target', 'body' => 'old'],
				'other'  => ['id' => 'other', 'body' => 'keep'],
			]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->validator
			->expects($this->once())
			->method('validate')
			->with('blog', 'comments', $this->callback(fn (array $d): bool => $d['id'] === 'target' && $d['body'] === 'new'));

		$updated = $this->createMock(ObjectData::class);
		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with(
				$this->equalTo('blog'),
				$this->equalTo('post-1'),
				$this->callback(fn (array $data): bool => $data['comments']['target']['body'] === 'new'
						&& $data['comments']['other']['body'] === 'keep'),
			)
			->willReturn($updated);

		$result = $this->updater->updateDeckItem('blog', 'post-1', 'comments', 'target', ['body' => 'new']);

		expect($result)->toBe($updated);
	});

	test('updateDeckItem forces id to match the URL itemId', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData(['target' => ['id' => 'target']]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->validator
			->expects($this->once())
			->method('validate')
			->with($this->anything(), $this->anything(), $this->callback(fn (array $d): bool => $d['id'] === 'target'));

		$this->objectUpdater
			->method('updateObject')
			->willReturn($this->createMock(ObjectData::class));

		// Caller tries to rename the item by providing a different id — should be ignored.
		$this->updater->updateDeckItem('blog', 'post-1', 'comments', 'target', ['id' => 'tamper', 'body' => 'x']);
	});

	test('updateDeckItem throws when the property is not a deck', function (): void {
		$object = new ObjectData('post-1', ['title' => new StringData('Hello')]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');

		expect(fn () => $this->updater->updateDeckItem('blog', 'post-1', 'title', 'x', []))
			->toThrow(InvalidArgumentException::class, "Property 'title' is not a deck property");
	});

	test('updateDeckItem throws when the itemId does not exist', function (): void {
		$object = new ObjectData('post-1', ['comments' => new DeckData([])]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');

		expect(fn () => $this->updater->updateDeckItem('blog', 'post-1', 'comments', 'ghost', []))
			->toThrow(InvalidArgumentException::class, "Deck item 'ghost' does not exist");
	});

	test('updateDeckItem propagates validation exceptions and does not persist', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData(['target' => ['id' => 'target']]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->validator
			->method('validate')
			->willThrowException(new InvalidArgumentException('schema mismatch'));

		$this->objectUpdater->expects($this->never())->method('updateObject');

		expect(fn () => $this->updater->updateDeckItem('blog', 'post-1', 'comments', 'target', ['body' => 'x']))
			->toThrow(InvalidArgumentException::class, 'schema mismatch');
	});
});
