<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Property\Service\DeckItemValidator;
use TotalCMS\Domain\Property\Service\PropertyFactory;

describe('DeckItemSaver', function (): void {
	beforeEach(function (): void {
		$this->objectFetcher   = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater   = $this->createMock(ObjectUpdater::class);
		$this->propertyFactory = $this->createMock(PropertyFactory::class);
		$this->validator       = $this->createMock(DeckItemValidator::class);

		$this->saver = new DeckItemSaver(
			$this->objectFetcher,
			$this->objectUpdater,
			$this->propertyFactory,
			$this->validator,
		);

		// Default pass-through: processIndividualDeckItem returns its input
		$this->propertyFactory
			->method('processIndividualDeckItem')
			->willReturnArgument(2);
	});

	test('saveDeckItem stamps id into the data, processes, validates, and updates', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData([
				'existing' => ['id' => 'existing', 'body' => 'hi'],
			]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->validator
			->expects($this->once())
			->method('validate')
			->with('blog', 'comments', $this->callback(fn (array $d): bool => $d['id'] === 'new' && $d['body'] === 'fresh'));

		$updated = $this->createMock(ObjectData::class);
		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with(
				$this->equalTo('blog'),
				$this->equalTo('post-1'),
				$this->callback(fn (array $data): bool => isset($data['comments']['existing'])
						&& isset($data['comments']['new'])
						&& $data['comments']['new']['body'] === 'fresh'),
			)
			->willReturn($updated);

		$result = $this->saver->saveDeckItem('blog', 'post-1', 'comments', 'new', ['body' => 'fresh']);

		expect($result)->toBe($updated);
	});

	test('saveDeckItem overwrites caller-supplied id with the URL itemId', function (): void {
		$object = new ObjectData('post-1', ['comments' => new DeckData([])]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->validator
			->expects($this->once())
			->method('validate')
			->with($this->anything(), $this->anything(), $this->callback(fn (array $d): bool => $d['id'] === 'url_id'));

		$this->objectUpdater
			->method('updateObject')
			->willReturn($this->createMock(ObjectData::class));

		$this->saver->saveDeckItem('blog', 'post-1', 'comments', 'url_id', ['id' => 'different', 'body' => 'x']);
	});

	test('saveDeckItem throws when the property is not a deck', function (): void {
		$object = new ObjectData('post-1', ['title' => new StringData('Hello')]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');

		expect(fn () => $this->saver->saveDeckItem('blog', 'post-1', 'title', 'x', []))
			->toThrow(InvalidArgumentException::class, "Property 'title' is not a deck property");
	});

	test('saveDeckItem throws when the itemId already exists', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData(['dupe' => ['id' => 'dupe']]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');
		$this->validator->expects($this->never())->method('validate');

		expect(fn () => $this->saver->saveDeckItem('blog', 'post-1', 'comments', 'dupe', []))
			->toThrow(InvalidArgumentException::class, "Deck item 'dupe' already exists");
	});

	test('saveDeckItem propagates validation exceptions and does not persist', function (): void {
		$object = new ObjectData('post-1', ['comments' => new DeckData([])]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->validator
			->method('validate')
			->willThrowException(new InvalidArgumentException('bad data'));

		$this->objectUpdater->expects($this->never())->method('updateObject');

		expect(fn () => $this->saver->saveDeckItem('blog', 'post-1', 'comments', 'new', ['body' => 'x']))
			->toThrow(InvalidArgumentException::class, 'bad data');
	});
});
