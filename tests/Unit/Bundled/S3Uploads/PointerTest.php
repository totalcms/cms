<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Storage\Pointer;

require_once __DIR__ . '/helpers.php';

describe('Pointer', function (): void {
	$valid = [
		'id'         => '9f2c1a7e',
		'key'        => 'site/brochures/spring/brochure/9f2c1a7e-brochure-2026.pdf',
		'name'       => 'Brochure 2026.pdf',
		'size'       => 48211334,
		'mime'       => 'application/pdf',
		'etag'       => '3d0f6c',
		'uploadedAt' => '2026-09-05T14:02:11+00:00',
		'uploadedBy' => 'joe',
	];

	test('a stored value round-trips', function () use ($valid): void {
		expect(Pointer::fromArray($valid)?->toArray())->toBe($valid);
	});

	test('an etag stored with the quotes the bucket sends is read without them', function () use ($valid): void {
		expect(Pointer::fromArray(['etag' => '"3d0f6c"'] + $valid)?->etag)->toBe('3d0f6c');
	});

	test('duration is kept when set and left out when not', function () use ($valid): void {
		expect(Pointer::fromArray($valid + ['duration' => 2714.3])?->toArray()['duration'])->toBe(2714.3)
			->and(Pointer::fromArray($valid)?->toArray())->not->toHaveKey('duration');
	});

	test('anything that is not a pointer is null', function (mixed $value): void {
		expect(Pointer::fromArray($value))->toBeNull();
	})->with([
		'null'            => [null],
		'a string'        => ['site/a.pdf'],
		'an empty array'  => [[]],
		'no key'          => [['id' => '9f2c1a7e']],
		'empty key'       => [['id' => '9f2c1a7e', 'key' => '']],
		'key not text'    => [['id' => '9f2c1a7e', 'key' => ['x']]],
		'no id'           => [['key' => 'site/a.pdf']],
		'id too short'    => [['id' => '9f2c1a7', 'key' => 'site/a.pdf']],
		'id uppercase'    => [['id' => '9F2C1A7E', 'key' => 'site/a.pdf']],
		'id not hex'      => [['id' => '9f2c1a7g', 'key' => 'site/a.pdf']],
	]);

	test('missing details default, and the name falls back to the key', function (): void {
		$pointer = Pointer::fromArray(['id' => '9f2c1a7e', 'key' => 'site/x/9f2c1a7e-a.pdf']);

		expect($pointer?->name)->toBe('9f2c1a7e-a.pdf')
			->and($pointer?->size)->toBe(0)
			->and($pointer?->mime)->toBe('application/octet-stream')
			->and($pointer?->duration)->toBeNull();
	});

	test('a file name loses control characters and is capped at 255 bytes', function (): void {
		expect(Pointer::cleanName("a\x00b\x1F\x7Fc.pdf"))->toBe('abc.pdf')
			->and(Pointer::cleanName('  spaced.pdf  '))->toBe('spaced.pdf')
			->and(strlen(Pointer::cleanName(str_repeat('é', 200))))->toBe(254)
			->and(Pointer::cleanName(''))->toBe('file');
	});
});
