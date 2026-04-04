<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Service\SchemaSaver;

// --------------------------------------------------
// sanitizeRequiredAndIndex
// --------------------------------------------------

it('keeps valid required properties', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => [], 'body' => []],
		'required'   => ['id', 'title'],
		'index'      => ['id'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data);
	expect($result['required'])->toBe(['id', 'title']);
	expect($result['index'])->toBe(['id']);
});

it('strips invalid required and index properties', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => []],
		'required'   => ['id', 'nonexistent'],
		'index'      => ['id', 'missing'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data);
	expect($result['required'])->toBe(['id']);
	expect($result['index'])->toBe(['id']);
});

it('allows inherited properties in required and index', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => []],
		'required'   => ['id', 'title', 'parentField'],
		'index'      => ['id', 'parentField'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data, ['parentField', 'otherParentField']);
	expect($result['required'])->toBe(['id', 'title', 'parentField']);
	expect($result['index'])->toBe(['id', 'parentField']);
});

it('still strips truly invalid properties when inherited properties are provided', function (): void {
	$data = [
		'properties' => ['id' => []],
		'required'   => ['id', 'parentField', 'bogus'],
		'index'      => ['id', 'bogus'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data, ['parentField']);
	expect($result['required'])->toBe(['id', 'parentField']);
	expect($result['index'])->toBe(['id']);
});

it('handles empty inherited properties array', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => []],
		'required'   => ['id', 'missing'],
		'index'      => ['id'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data, []);
	expect($result['required'])->toBe(['id']);
	expect($result['index'])->toBe(['id']);
});

it('returns unchanged data when no properties key', function (): void {
	$data   = ['id' => 'test'];
	$result = SchemaSaver::sanitizeRequiredAndIndex($data);
	expect($result)->toBe($data);
});
