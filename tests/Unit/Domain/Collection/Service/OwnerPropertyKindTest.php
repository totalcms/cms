<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\OwnerPropertyKind;

/*
 * Which properties can hold an owner. A list owner must hold strings: a
 * gallery is an array of image objects, and stamping ["alex"] into it would
 * break every create on the collection.
 */
describe('OwnerPropertyKind::of', function (): void {
	$ref = static fn (string $type): string => "https://www.totalcms.co/schemas/properties/{$type}.json";

	it('reads a string property as a scalar owner', function (): void {
		expect(OwnerPropertyKind::of(['type' => 'string', 'field' => 'text']))->toBe(OwnerPropertyKind::SCALAR);
	});

	it('reads an untyped property as a scalar owner', function (): void {
		expect(OwnerPropertyKind::of(['field' => 'input']))->toBe(OwnerPropertyKind::SCALAR);
	});

	it('reads a string-valued ref as a scalar owner', function () use ($ref): void {
		expect(OwnerPropertyKind::of(['$ref' => $ref('slug'), 'field' => 'id']))->toBe(OwnerPropertyKind::SCALAR);
	});

	it('reads a list ref as a list owner', function () use ($ref): void {
		expect(OwnerPropertyKind::of(['$ref' => $ref('list'), 'field' => 'list']))->toBe(OwnerPropertyKind::LIST);
	});

	it('reads an inline array of strings as a list owner', function (): void {
		expect(OwnerPropertyKind::of(['type' => 'array', 'items' => ['type' => 'string'], 'field' => 'multicheckbox']))->toBe(OwnerPropertyKind::LIST);
	});

	it('reads an inline array of string-or-null items as a list owner', function (): void {
		expect(OwnerPropertyKind::of(['type' => 'array', 'items' => ['type' => ['string', 'null']]]))->toBe(OwnerPropertyKind::LIST);
	});

	it('reads an inline array with no items as a list owner', function (): void {
		expect(OwnerPropertyKind::of(['type' => 'array', 'field' => 'list']))->toBe(OwnerPropertyKind::LIST);
	});

	it('rejects a gallery', function () use ($ref): void {
		expect(OwnerPropertyKind::of(['$ref' => $ref('gallery'), 'field' => 'gallery']))->toBeNull();
	});

	it('rejects an inline array of objects', function (): void {
		expect(OwnerPropertyKind::of(['type' => 'array', 'items' => ['type' => 'object']]))->toBeNull();
	});

	it('rejects a boolean', function (): void {
		expect(OwnerPropertyKind::of(['type' => 'boolean', 'field' => 'toggle']))->toBeNull();
	});

	it('rejects an image ref', function () use ($ref): void {
		expect(OwnerPropertyKind::of(['$ref' => $ref('image'), 'field' => 'image']))->toBeNull();
	});

	it('rejects a color ref', function () use ($ref): void {
		expect(OwnerPropertyKind::of(['$ref' => $ref('color'), 'field' => 'color']))->toBeNull();
	});
});
