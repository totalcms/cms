<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Storage\SerializerFactory;

// Every data object and repository used to build its own Serializer, and
// ObjectData did so per record. One shared instance serves them all.

test('the JSON serializer is built once and shared', function (): void {
	expect(SerializerFactory::json())->toBe(SerializerFactory::json());
});

test('the shared serializer hydrates a data object and encodes JSON', function (): void {
	$collection = SerializerFactory::json()->deserialize('{"id":"blog"}', CollectionData::class, 'json');

	expect($collection)->toBeInstanceOf(CollectionData::class)
		->and($collection->id)->toBe('blog')
		->and(SerializerFactory::json()->serialize(['a' => 1], 'json'))->toBe('{"a":1}');
});
