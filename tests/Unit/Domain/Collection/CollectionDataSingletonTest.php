<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;

test('singleton defaults to false and is present in toArray', function (): void {
	$c         = new CollectionData();
	$c->id     = 'settings';
	$c->schema = 'settings';

	expect($c->singleton)->toBeFalse();
	expect($c->toArray())->toHaveKey('singleton');
	expect($c->toArray()['singleton'])->toBeFalse();
});

test('singleton round-trips through toArray', function (): void {
	$c            = new CollectionData();
	$c->id        = 'settings';
	$c->schema    = 'settings';
	$c->singleton = true;

	expect($c->toArray()['singleton'])->toBeTrue();
});
