<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

require_once __DIR__ . '/helpers.php';

describe('S3Fields', function (): void {
	$properties = [
		'title'    => ['type' => 'string', 'field' => 'text'],
		'brochure' => ['type' => 'json', 'field' => 's3file', 'settings' => ['rules' => ['size' => ['max' => 1000]]]],
		'files'    => ['type' => 'json', 'field' => 's3depot'],
		'cover'    => ['type' => 'file', 'field' => 'file'],
		'slides'   => ['type' => 'deck', 'field' => 'deck', 'settings' => ['schema' => ['properties' => ['clip' => ['field' => 's3file']]]]],
	];

	test('only top-level s3 properties count', function () use ($properties): void {
		$fields = new S3Fields(s3SchemaFetcher($this, $properties));

		expect($fields->forCollection('blog'))->toBe([
			'brochure' => ['field' => 's3file', 'settings' => ['rules' => ['size' => ['max' => 1000]]]],
			'files'    => ['field' => 's3depot', 'settings' => []],
		])
			->and($fields->property('blog', 'brochure')['field'] ?? null)->toBe('s3file')
			->and($fields->property('blog', 'cover'))->toBeNull()
			->and($fields->property('blog', 'clip'))->toBeNull()
			->and($fields->property('blog', 'nope'))->toBeNull();
	});

	test('an s3 property holds a file only when it is stored as json', function (array $definition, bool $json): void {
		$fields = new S3Fields(s3SchemaFetcher($this, ['brochure' => ['field' => 's3file'] + $definition]));

		expect($fields->storesJson('blog', 'brochure'))->toBe($json);
	})->with([
		'type json'                    => [['type' => 'json'], true],
		'saved: a $ref to json'        => [['$ref' => 'https://www.totalcms.co/schemas/properties/json.json'], true],
		'left as a string'             => [['type' => 'string'], false],
		'left as a core file'          => [['$ref' => 'https://www.totalcms.co/schemas/properties/file.json'], false],
		'no type at all (hand-edited)' => [[], false],
	]);

	test('a schema that cannot be read has no s3 properties', function (): void {
		$schemas = $this->createMock(SchemaFetcher::class);
		$schemas->method('fetchSchemaForCollection')->willThrowException(new RuntimeException('gone'));

		expect((new S3Fields($schemas))->forCollection('blog'))->toBe([]);
	});

	test('pointers reads one pointer from an s3file and the valid ones from an s3depot', function (): void {
		$a = ['id' => '0a1b2c3d', 'key' => 'site/a.pdf'];
		$b = ['id' => '1a1b2c3d', 'key' => 'site/b.pdf'];

		expect(array_map(fn (Pointer $p): string => $p->key, S3Fields::pointers($a, 's3file')))->toBe(['site/a.pdf'])
			->and(S3Fields::pointers(null, 's3file'))->toBe([])
			->and(S3Fields::pointers('junk', 's3file'))->toBe([])
			->and(array_map(fn (Pointer $p): string => $p->key, S3Fields::pointers([$a, 'junk', ['id' => 'x'], $b], 's3depot')))->toBe(['site/a.pdf', 'site/b.pdf'])
			->and(S3Fields::pointers($a, 's3depot'))->toBe([])
			->and(S3Fields::pointers([$a], 'file'))->toBe([]);
	});
});
