<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;

test('seo defaults to empty and is omitted from toArray', function (): void {
	$c         = new CollectionData();
	$c->id     = 'posts';
	$c->schema = 'blog';

	expect($c->seo)->toBe([]);
	expect($c->toArray())->not->toHaveKey('seo');
});

test('seo round-trips through toArray when set', function (): void {
	$c         = new CollectionData();
	$c->id     = 'posts';
	$c->schema = 'blog';
	$c->seo    = ['type' => 'article', 'description' => 'summary', 'image' => 'image'];

	expect($c->toArray()['seo'])->toBe(['type' => 'article', 'description' => 'summary', 'image' => 'image']);
});
