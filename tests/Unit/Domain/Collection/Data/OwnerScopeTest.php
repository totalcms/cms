<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\OwnerScope;

/*
 * The owner scope on member lists is exact string equality, the same
 * comparison CollectionAccessMiddleware::ownerAllows() makes when an object
 * is opened. It is deliberately not the ObjectFilter `include` grammar
 * (case-insensitive, `*` wildcards, comma-split clauses, true/false coercion).
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

	it('does not match a non-scalar owner', function (): void {
		expect((new OwnerScope('owner', 'alex'))->matches(['owner' => ['alex']]))->toBeFalse();
		expect((new OwnerScope('owner', 'Array'))->matches(['owner' => ['alex']]))->toBeFalse();
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
