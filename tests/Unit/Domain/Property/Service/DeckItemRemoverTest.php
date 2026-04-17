<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Service\DeckItemRemover;

/**
 * Coverage for the deck-item delete service used by
 * `DELETE /collections/{c}/{id}/{p}/deck/{itemId}`.
 */
describe('DeckItemRemover', function (): void {
	beforeEach(function (): void {
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->remover       = new DeckItemRemover($this->objectFetcher, $this->objectUpdater);
	});

	test('removeDeckItem builds a new deck array without the target item and updates', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData([
				'keep'   => ['id' => 'keep', 'body' => 'A'],
				'remove' => ['id' => 'remove', 'body' => 'B'],
			]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$updated = $this->createMock(ObjectData::class);
		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with(
				$this->equalTo('blog'),
				$this->equalTo('post-1'),
				$this->callback(
					// The deck property must no longer contain `remove`
					fn (array $data): bool => isset($data['comments'])
						&& is_array($data['comments'])
						&& !array_key_exists('remove', $data['comments'])
						&& array_key_exists('keep', $data['comments'])
				),
			)
			->willReturn($updated);

		$result = $this->remover->removeDeckItem('blog', 'post-1', 'comments', 'remove');

		expect($result)->toBe($updated);
	});

	test('removeDeckItem throws when the property is not a deck', function (): void {
		$object = new ObjectData('post-1', ['title' => new StringData('Hello')]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);

		expect(fn () => $this->remover->removeDeckItem('blog', 'post-1', 'title', 'x'))
			->toThrow(InvalidArgumentException::class, "Property 'title' is not a deck property");
	});

	test('removeDeckItem throws when the itemId does not exist', function (): void {
		$object = new ObjectData('post-1', [
			'comments' => new DeckData(['keep' => ['id' => 'keep']]),
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');

		expect(fn () => $this->remover->removeDeckItem('blog', 'post-1', 'comments', 'missing'))
			->toThrow(InvalidArgumentException::class, "Deck item 'missing' does not exist");
	});
});
