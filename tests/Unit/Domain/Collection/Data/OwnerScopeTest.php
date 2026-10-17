<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\OwnerScope;

/*
 * The owner scope on member lists is exact string equality (per entry for a
 * list owner), the same comparison CollectionAccessMiddleware::ownerAllows()
 * makes when an object is opened. It is deliberately not the ObjectFilter
 * `include` grammar (case-insensitive, `*` wildcards, comma-split clauses,
 * true/false coercion).
 */
describe('OwnerScope', function (): void {
	it('matches an exact owner value', function (): void {
		expect((new OwnerScope('owner', 'alex'))->matches(['id' => 'a', 'owner' => 'alex']))->toBeTrue();
	});

	it('does not match when only the case differs', function (): void {
		expect((new OwnerScope('owner', 'alex'))->matches(['id' => 'a', 'owner' => 'Alex']))->toBeFalse();
		expect((new OwnerScope('owner', 'Alex'))->matches(['id' => 'a', 'owner' => 'alex']))->toBeFalse();
	});

	it('treats a wildcard in the value literally', function (): void {
		$scope = new OwnerScope('owner', '*');

		expect($scope->matches(['owner' => 'alex']))->toBeFalse();
		expect($scope->matches(['owner' => '*']))->toBeTrue();
	});

	it('matches a value containing a comma exactly once', function (): void {
		$items = [
			['id' => 'a', 'owner' => 'smith,jo'],
			['id' => 'b', 'owner' => 'smith'],
			['id' => 'c', 'owner' => 'jo'],
		];

		expect((new OwnerScope('owner', 'smith,jo'))->filter($items))->toBe([['id' => 'a', 'owner' => 'smith,jo']]);
	});

	it('does not match an item without the property', function (): void {
		expect((new OwnerScope('owner', 'alex'))->matches(['id' => 'a', 'title' => 'x']))->toBeFalse();
		expect((new OwnerScope('owner', ''))->matches(['id' => 'a', 'owner' => null]))->toBeFalse();
	});

	it('does not match a non-scalar, non-list owner', function (): void {
		expect((new OwnerScope('owner', 'Array'))->matches(['owner' => ['alex']]))->toBeFalse();
		expect((new OwnerScope('owner', 'alex'))->matches(['owner' => new stdClass()]))->toBeFalse();
	});

	it('matches every co-owner in a list owner', function (): void {
		$item = ['id' => 'a', 'owner' => ['alex', 'sam']];

		expect((new OwnerScope('owner', 'alex'))->matches($item))->toBeTrue();
		expect((new OwnerScope('owner', 'sam'))->matches($item))->toBeTrue();
	});

	it('compares each list entry exactly', function (): void {
		$item = ['id' => 'a', 'owner' => ['alex', 'sam']];

		expect((new OwnerScope('owner', 'al'))->matches($item))->toBeFalse();
		expect((new OwnerScope('owner', 'Alex'))->matches($item))->toBeFalse();
		expect((new OwnerScope('owner', 'alex'))->matches(['owner' => ['alex ']]))->toBeFalse();
	});

	it('ignores nested arrays in a list owner', function (): void {
		$item = ['id' => 'a', 'owner' => ['alex', ['nested']]];

		expect((new OwnerScope('owner', 'alex'))->matches($item))->toBeTrue();
		expect((new OwnerScope('owner', 'nested'))->matches($item))->toBeFalse();
		expect((new OwnerScope('owner', 'Array'))->matches($item))->toBeFalse();
	});

	it('does not match an empty list owner', function (): void {
		expect((new OwnerScope('owner', ''))->matches(['owner' => []]))->toBeFalse();
	});

	it('filters in order and reindexes', function (): void {
		$items = [
			['id' => 'a', 'owner' => 'sam'],
			['id' => 'b', 'owner' => 'alex'],
			['id' => 'c', 'owner' => 'sam'],
			['id' => 'd', 'owner' => 'alex'],
		];

		expect((new OwnerScope('owner', 'alex'))->filter($items))->toBe([
			['id' => 'b', 'owner' => 'alex'],
			['id' => 'd', 'owner' => 'alex'],
		]);
	});

	it('gives each property and value its own cache key', function (): void {
		expect((new OwnerScope('owner', 'alex'))->cacheKey())
			->toBe((new OwnerScope('owner', 'alex'))->cacheKey())
			->not->toBe((new OwnerScope('owner', 'sam'))->cacheKey())
			->not->toBe((new OwnerScope('author', 'alex'))->cacheKey());
	});
});
