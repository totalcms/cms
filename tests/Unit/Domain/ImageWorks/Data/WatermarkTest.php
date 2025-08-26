<?php

use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;

describe('Watermark', function (): void {
	// -------------------------
	// Constructor and Defaults
	// -------------------------

	test('Watermark → creates with default null values', function (): void {
		$watermark = new Watermark();

		expect($watermark->mark)->toBeNull();
		expect($watermark->markpos)->toBeNull();
		expect($watermark->markw)->toBeNull();
		expect($watermark->markh)->toBeNull();
		expect($watermark->markx)->toBeNull();
		expect($watermark->marky)->toBeNull();
		expect($watermark->markfit)->toBeNull();
		expect($watermark->markpad)->toBeNull();
		expect($watermark->markalpha)->toBeNull();
		expect($watermark->path)->toBe(TextWatermarkFactory::WATERMARK_DIR);
	});

	test('Watermark → creates with all parameters set', function (): void {
		$watermark = new Watermark(
			mark: 'logo.png',
			markpos: 'bottom-right',
			markw: '100',
			markh: '50',
			markx: '10',
			marky: '10',
			markfit: 'contain',
			markpad: '5',
			markalpha: '80',
			path: 'custom/watermarks'
		);

		expect($watermark->mark)->toBe('logo.png');
		expect($watermark->markpos)->toBe('bottom-right');
		expect($watermark->markw)->toBe('100');
		expect($watermark->markh)->toBe('50');
		expect($watermark->markx)->toBe('10');
		expect($watermark->marky)->toBe('10');
		expect($watermark->markfit)->toBe('contain');
		expect($watermark->markpad)->toBe('5');
		expect($watermark->markalpha)->toBe('80');
		expect($watermark->path)->toBe('custom/watermarks');
	});

	test('Watermark → creates with partial parameters', function (): void {
		$watermark = new Watermark(
			mark: 'logo.png',
			markpos: 'center',
			markalpha: '50'
		);

		expect($watermark->mark)->toBe('logo.png');
		expect($watermark->markpos)->toBe('center');
		expect($watermark->markalpha)->toBe('50');
		expect($watermark->markw)->toBeNull();
		expect($watermark->markh)->toBeNull();
		expect($watermark->path)->toBe(TextWatermarkFactory::WATERMARK_DIR);
	});

	// -------------------------
	// toArray Method
	// -------------------------

	test('Watermark → toArray returns empty array when all properties null', function (): void {
		$watermark = new Watermark();
		$result    = $watermark->toArray();

		expect($result)->toBe([]);
	});

	test('Watermark → toArray filters out null values', function (): void {
		$watermark = new Watermark(
			mark: 'logo.png',
			markpos: null,
			markw: '100',
			markh: null,
			markalpha: '75'
		);
		$result = $watermark->toArray();

		expect($result)->toBe([
			'mark'      => 'logo.png',
			'markw'     => '100',
			'markalpha' => '75',
		]);
		expect($result)->not->toHaveKey('markpos');
		expect($result)->not->toHaveKey('markh');
	});

	test('Watermark → toArray includes all non-null properties', function (): void {
		$watermark = new Watermark(
			mark: 'watermark.png',
			markpos: 'bottom-right',
			markw: '150',
			markh: '75',
			markx: '20',
			marky: '20',
			markfit: 'cover',
			markpad: '10',
			markalpha: '90'
		);
		$result = $watermark->toArray();

		expect($result)->toBe([
			'mark'      => 'watermark.png',
			'markpos'   => 'bottom-right',
			'markw'     => '150',
			'markh'     => '75',
			'markx'     => '20',
			'marky'     => '20',
			'markfit'   => 'cover',
			'markpad'   => '10',
			'markalpha' => '90',
		]);
	});

	test('Watermark → toArray does not include path property', function (): void {
		$watermark = new Watermark(
			mark: 'logo.png',
			path: 'custom/path'
		);
		$result = $watermark->toArray();

		expect($result)->toBe([
			'mark' => 'logo.png',
		]);
		expect($result)->not->toHaveKey('path');
	});

	// -------------------------
	// isEmpty Method
	// -------------------------

	test('Watermark → isEmpty returns true when mark is null', function (): void {
		$watermark = new Watermark();

		expect($watermark->isEmpty())->toBe(true);
	});

	test('Watermark → isEmpty returns true when mark is explicitly null', function (): void {
		$watermark = new Watermark(mark: null);

		expect($watermark->isEmpty())->toBe(true);
	});

	test('Watermark → isEmpty returns false when mark is set', function (): void {
		$watermark = new Watermark(mark: 'logo.png');

		expect($watermark->isEmpty())->toBe(false);
	});

	test('Watermark → isEmpty returns false when mark is empty string', function (): void {
		$watermark = new Watermark(mark: '');

		expect($watermark->isEmpty())->toBe(false);
	});

	test('Watermark → isEmpty only checks mark property regardless of other properties', function (): void {
		$watermark = new Watermark(
			mark: null,
			markpos: 'center',
			markw: '100',
			markalpha: '50'
		);

		expect($watermark->isEmpty())->toBe(true);
	});

	// -------------------------
	// Readonly Property Behavior
	// -------------------------

	test('Watermark → is readonly class with immutable properties', function (): void {
		$watermark  = new Watermark(mark: 'test.png');
		$reflection = new ReflectionClass($watermark);

		expect($reflection->isReadOnly())->toBe(true);
	});

	// -------------------------
	// Integration Tests
	// -------------------------

	test('Watermark → typical usage scenario for image watermarking', function (): void {
		$watermark = new Watermark(
			mark: 'company-logo.png',
			markpos: 'bottom-right',
			markx: '10',
			marky: '10',
			markalpha: '85'
		);

		expect($watermark->isEmpty())->toBe(false);

		$params = $watermark->toArray();
		expect($params)->toHaveKey('mark');
		expect($params)->toHaveKey('markpos');
		expect($params)->toHaveKey('markx');
		expect($params)->toHaveKey('marky');
		expect($params)->toHaveKey('markalpha');
		expect($params)->not->toHaveKey('markw');
		expect($params)->not->toHaveKey('markh');

		expect($watermark->path)->toBe(TextWatermarkFactory::WATERMARK_DIR);
	});

	test('Watermark → text watermark scenario', function (): void {
		$watermark = new Watermark(
			mark: 'text-watermark.png',
			markpos: 'center',
			markalpha: '70',
			path: '.watermarks'
		);

		expect($watermark->isEmpty())->toBe(false);
		expect($watermark->path)->toBe('.watermarks');

		$params = $watermark->toArray();
		expect($params['mark'])->toBe('text-watermark.png');
		expect($params['markpos'])->toBe('center');
		expect($params['markalpha'])->toBe('70');
	});

	test('Watermark → disabled watermark scenario', function (): void {
		$watermark = new Watermark();

		expect($watermark->isEmpty())->toBe(true);
		expect($watermark->toArray())->toBe([]);
		expect($watermark->path)->toBe(TextWatermarkFactory::WATERMARK_DIR);
	});
});
