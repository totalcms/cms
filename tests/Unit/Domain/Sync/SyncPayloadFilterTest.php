<?php

declare(strict_types=1);

use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Sync\Service\SyncPayloadFilter;

beforeEach(function (): void {
	$this->filter  = new SyncPayloadFilter();
	$this->payload = [
		'schemas'     => [['id' => 'post'], ['id' => 'page']],
		'templates'   => [['id' => 'a'], ['id' => 'b']],
		'objects'     => [
			['collection' => 'blog', 'id' => '1'],
			['collection' => 'blog', 'id' => '2'],
			['collection' => 'news', 'id' => '9'],
		],
		'collections' => ['custom' => [['id' => 'news'], ['id' => PlaygroundData::COLLECTION_ID]], 'reserved' => ['blog', ['id' => 'image']]],
	];
});

test('each filter narrows its own section and the playground collection never travels', function (): void {
	$out = $this->filter->apply($this->payload, ['post'], ['b'], ['blog' => ['2']], ['blog']);

	expect($out['schemas'])->toBe([['id' => 'post']])
		->and($out['templates'])->toBe([['id' => 'b']])
		->and($out['objects'])->toBe([['collection' => 'blog', 'id' => '2']])
		->and($out['collections'])->toBe(['custom' => [], 'reserved' => ['blog']]);
});

test('a null object list for a collection means all of its objects', function (): void {
	$out = $this->filter->apply($this->payload, null, null, ['blog' => null]);

	expect(array_column($out['objects'], 'id'))->toBe(['1', '2'])
		->and($out['schemas'])->toHaveCount(2);
});

test('the playground collection is stripped even with no filters at all', function (): void {
	$out = $this->filter->apply($this->payload, null, null);

	expect($out['collections']['custom'])->toBe([['id' => 'news']]);
});

test('collections are counted across both arms', function (): void {
	expect(SyncPayloadFilter::countCollections(['custom' => [['id' => 'a']], 'reserved' => ['blog', 'image']]))->toBe(3)
		->and(SyncPayloadFilter::countCollections([]))->toBe(0);
});

test('a seeding push keeps everything but the seedable objects on the mirror leg', function (): void {
	$jumpstart              = new JumpStartData();
	$jumpstart->schemas     = [['id' => 'post']];
	$jumpstart->collections = ['reserved' => ['builder-pages'], 'custom' => []];
	$jumpstart->objects     = [
		['collection' => 'builder-pages', 'id' => 'home'],
		['collection' => 'legal', 'id' => 'terms'],
	];

	// builder-pages is one of the five feature-flag collections the mirror leg
	// carries objects for, so it can never be seeded even when asked.
	[$mirror, $seed] = $this->filter->splitSeeded($jumpstart, ['legal' => null, 'builder-pages' => null]);

	expect(array_column($mirror->objects, 'collection'))->toBe(['builder-pages'])
		->and(array_column($seed->objects, 'collection'))->toBe(['legal'])
		->and($seed->schemas)->toBe([])
		->and($seed->collections)->toBe(['reserved' => [], 'custom' => []])
		->and($mirror->schemas)->toBe([['id' => 'post']]);
});
