<?php

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Schema\Data\SchemaData;

describe('TotalForm::extractMediaTags', function (): void {
	test('image → flattens, dedupes, drops empties across objects', function (): void {
		$objects = [
			['id' => 'a', 'myimage' => ['name' => 'a.jpg', 'tags' => ['sky', 'sea']]],
			['id' => 'b', 'myimage' => ['name' => 'b.jpg', 'tags' => ['sea', '']]],
			['id' => 'c', 'myimage' => ['name' => 'c.jpg', 'tags' => ['sand']]],
			['id' => 'd'], // no media field at all
		];

		expect(TotalForm::extractMediaTags($objects, 'myimage', 'image'))
			->toBe(['sky', 'sea', 'sand']);
	});

	test('gallery → collects tags across all gallery items and objects', function (): void {
		$objects = [
			['id' => 'a', 'mygallery' => [
				'img1' => ['name' => '1.jpg', 'tags' => ['blue', 'wide']],
				'img2' => ['name' => '2.jpg', 'tags' => ['blue']],
			]],
			['id' => 'b', 'mygallery' => [
				'img3' => ['name' => '3.jpg', 'tags' => ['tall']],
			]],
		];

		expect(TotalForm::extractMediaTags($objects, 'mygallery', 'gallery'))
			->toBe(['blue', 'wide', 'tall']);
	});

	test('file → single-object tags (same shape as image)', function (): void {
		$objects = [
			['id' => 'a', 'myfile' => ['name' => 'a.pdf', 'tags' => ['spec', 'pdf']]],
			['id' => 'b', 'myfile' => ['name' => 'b.zip', 'tags' => ['pdf', 'archive']]],
		];

		expect(TotalForm::extractMediaTags($objects, 'myfile', 'file'))
			->toBe(['spec', 'pdf', 'archive']);
	});

	test('returns an empty array when no tags are present', function (): void {
		expect(TotalForm::extractMediaTags([['id' => 'a']], 'myimage', 'image'))->toBe([]);
	});
});

describe('TotalForm::isPropertyIndexed', function (): void {
	test('true only when the property is in the schema index', function (): void {
		$form = (new ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
		$schema = new SchemaData();
		$schema->index = ['myimage', 'id'];
		$form->schemaData = $schema;

		expect($form->isPropertyIndexed('myimage'))->toBeTrue();
		expect($form->isPropertyIndexed('other'))->toBeFalse();
	});

	test('false when there is no schema', function (): void {
		$form = (new ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
		expect($form->isPropertyIndexed('myimage'))->toBeFalse();
	});
});

describe('TotalForm::mediaTagsForCollection', function (): void {
	test('reads the index and returns extracted tags', function (): void {
		$index = new IndexData([
			['id' => 'a', 'myimage' => ['tags' => ['sky', 'sea']]],
			['id' => 'b', 'myimage' => ['tags' => ['sea', 'sand']]],
		]);

		// Assert the fallback to $this->collection: no collection arg is passed below,
		// so fetchIndex must be called with 'photos'.
		$reader = test()->createMock(IndexReader::class);
		$reader->expects(test()->once())->method('fetchIndex')->with('photos')->willReturn($index);

		$form = (new ReflectionClass(TotalForm::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(TotalForm::class, 'collectionReader'))->setValue($form, $reader);
		(new ReflectionProperty(TotalForm::class, 'collection'))->setValue($form, 'photos');

		expect($form->mediaTagsForCollection('myimage', 'image'))->toBe(['sky', 'sea', 'sand']);
	});
});
