<?php

declare(strict_types = 1);

namespace Tests\Unit\ImageWorks\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;

final class WatermarkTest extends TestCase
{
	public function testConstructorWithDefaults(): void
	{
		$watermark = new Watermark();

		$this->assertNull($watermark->mark);
		$this->assertNull($watermark->markpos);
		$this->assertNull($watermark->markw);
		$this->assertNull($watermark->markh);
		$this->assertNull($watermark->markx);
		$this->assertNull($watermark->marky);
		$this->assertNull($watermark->markfit);
		$this->assertNull($watermark->markpad);
		$this->assertNull($watermark->markalpha);
		$this->assertEquals(TextWatermarkFactory::WATERMARK_DIR, $watermark->path);
	}

	public function testConstructorWithAllParameters(): void
	{
		$watermark = new Watermark(
			mark: 'logo.png',
			markpos: 'top-left',
			markw: '100',
			markh: '50',
			markx: '10',
			marky: '10',
			markfit: 'contain',
			markpad: '5',
			markalpha: '80',
			path: '/custom/path'
		);

		$this->assertEquals('logo.png', $watermark->mark);
		$this->assertEquals('top-left', $watermark->markpos);
		$this->assertEquals('100', $watermark->markw);
		$this->assertEquals('50', $watermark->markh);
		$this->assertEquals('10', $watermark->markx);
		$this->assertEquals('10', $watermark->marky);
		$this->assertEquals('contain', $watermark->markfit);
		$this->assertEquals('5', $watermark->markpad);
		$this->assertEquals('80', $watermark->markalpha);
		$this->assertEquals('/custom/path', $watermark->path);
	}

	public function testConstructorWithPartialParameters(): void
	{
		$watermark = new Watermark(
			mark: 'watermark.png',
			markpos: 'bottom-right',
			markalpha: '75'
		);

		$this->assertEquals('watermark.png', $watermark->mark);
		$this->assertEquals('bottom-right', $watermark->markpos);
		$this->assertEquals('75', $watermark->markalpha);
		$this->assertNull($watermark->markw);
		$this->assertNull($watermark->markh);
		$this->assertNull($watermark->markx);
		$this->assertNull($watermark->marky);
		$this->assertNull($watermark->markfit);
		$this->assertNull($watermark->markpad);
		$this->assertEquals(TextWatermarkFactory::WATERMARK_DIR, $watermark->path);
	}

	public function testToArrayWithAllParameters(): void
	{
		$watermark = new Watermark(
			mark: 'test.png',
			markpos: 'center',
			markw: '200',
			markh: '100',
			markx: '20',
			marky: '30',
			markfit: 'cover',
			markpad: '15',
			markalpha: '90'
		);

		$array = $watermark->toArray();

		$expected = [
			'mark'      => 'test.png',
			'markpos'   => 'center',
			'markw'     => '200',
			'markh'     => '100',
			'markx'     => '20',
			'marky'     => '30',
			'markfit'   => 'cover',
			'markpad'   => '15',
			'markalpha' => '90',
		];

		$this->assertEquals($expected, $array);
	}

	public function testToArrayWithPartialParameters(): void
	{
		$watermark = new Watermark(
			mark: 'partial.png',
			markpos: 'top-right',
			markalpha: '60'
		);

		$array = $watermark->toArray();

		$expected = [
			'mark'      => 'partial.png',
			'markpos'   => 'top-right',
			'markalpha' => '60',
		];

		$this->assertEquals($expected, $array);
		$this->assertArrayNotHasKey('markw', $array);
		$this->assertArrayNotHasKey('markh', $array);
		$this->assertArrayNotHasKey('markx', $array);
		$this->assertArrayNotHasKey('marky', $array);
		$this->assertArrayNotHasKey('markfit', $array);
		$this->assertArrayNotHasKey('markpad', $array);
	}

	public function testToArrayFiltersNullValues(): void
	{
		$watermark = new Watermark(
			mark: 'filter_test.png',
			markpos: null,
			markw: '150',
			markh: null,
			markalpha: '70'
		);

		$array = $watermark->toArray();

		$expected = [
			'mark'      => 'filter_test.png',
			'markw'     => '150',
			'markalpha' => '70',
		];

		$this->assertEquals($expected, $array);
		$this->assertArrayNotHasKey('markpos', $array);
		$this->assertArrayNotHasKey('markh', $array);
	}

	public function testToArrayWithEmptyWatermark(): void
	{
		$watermark = new Watermark();

		$array = $watermark->toArray();

		$this->assertEquals([], $array);
	}

	public function testIsEmptyWithNullMark(): void
	{
		$watermark = new Watermark();

		$this->assertTrue($watermark->isEmpty());
	}

	public function testIsEmptyWithMark(): void
	{
		$watermark = new Watermark(mark: 'has_mark.png');

		$this->assertFalse($watermark->isEmpty());
	}

	public function testIsEmptyOnlyChecksMarkProperty(): void
	{
		// Even with other properties set, if mark is null, it's empty
		$watermark = new Watermark(
			mark: null,
			markpos: 'center',
			markw: '100',
			markalpha: '50'
		);

		$this->assertTrue($watermark->isEmpty());
	}

	public function testIsEmptyWithEmptyStringMark(): void
	{
		// Empty string is not null, so watermark is not empty
		$watermark = new Watermark(mark: '');

		$this->assertFalse($watermark->isEmpty());
	}

	public function testReadOnlyProperties(): void
	{
		$watermark = new Watermark(mark: 'readonly_test.png');

		// This should fail with a fatal error if we try to modify
		// Since we can't test fatal errors easily, we just verify the class is readonly
		$reflection = new \ReflectionClass($watermark);
		$this->assertTrue($reflection->isReadOnly());
	}

	public function testWatermarkWithTextWatermarkFactoryConstant(): void
	{
		$watermark = new Watermark();

		$this->assertEquals(TextWatermarkFactory::WATERMARK_DIR, $watermark->path);
	}

	public function testWatermarkWithCustomPath(): void
	{
		$customPath = '/custom/watermark/directory';
		$watermark  = new Watermark(path: $customPath);

		$this->assertEquals($customPath, $watermark->path);
	}

	public function testAllValidGlideWatermarkPositions(): void
	{
		$positions = [
			'top-left', 'top', 'top-right',
			'left', 'center', 'right',
			'bottom-left', 'bottom', 'bottom-right',
		];

		foreach ($positions as $position) {
			$watermark = new Watermark(
				mark: 'position_test.png',
				markpos: $position
			);

			$this->assertEquals($position, $watermark->markpos);
			$this->assertFalse($watermark->isEmpty());
		}
	}

	public function testNumericStringParameters(): void
	{
		$watermark = new Watermark(
			mark: 'numeric_test.png',
			markw: '250',
			markh: '125',
			markx: '5',  // Non-zero value
			marky: '10', // Non-zero value
			markpad: '10',
			markalpha: '100'
		);

		$array = $watermark->toArray();

		$this->assertEquals('250', $array['markw']);
		$this->assertEquals('125', $array['markh']);
		$this->assertEquals('5', $array['markx']);
		$this->assertEquals('10', $array['marky']);
		$this->assertEquals('10', $array['markpad']);
		$this->assertEquals('100', $array['markalpha']);
	}

	public function testArrayFilterBehaviorWithFalsyValues(): void
	{
		// Test that array_filter removes falsy values like '0'
		$watermark = new Watermark(
			mark: 'falsy_test.png',
			markx: '0',    // This will be filtered out
			marky: '0',    // This will be filtered out
			markpad: '0'   // This will be filtered out
		);

		$array = $watermark->toArray();

		$this->assertEquals(['mark' => 'falsy_test.png'], $array);
		$this->assertArrayNotHasKey('markx', $array);
		$this->assertArrayNotHasKey('marky', $array);
		$this->assertArrayNotHasKey('markpad', $array);
	}

	public function testWatermarkFitOptions(): void
	{
		$fitOptions = ['contain', 'max', 'fill', 'stretch', 'crop'];

		foreach ($fitOptions as $fit) {
			$watermark = new Watermark(
				mark: 'fit_test.png',
				markfit: $fit
			);

			$this->assertEquals($fit, $watermark->markfit);
			$array = $watermark->toArray();
			$this->assertEquals($fit, $array['markfit']);
		}
	}

	public function testComplexWatermarkScenario(): void
	{
		// Test a realistic watermark configuration
		$watermark = new Watermark(
			mark: 'company_logo.png',
			markpos: 'bottom-right',
			markw: '20%',  // Percentage width
			markfit: 'contain',
			markpad: '20',
			markalpha: '75'
		);

		$this->assertFalse($watermark->isEmpty());

		$array    = $watermark->toArray();
		$expected = [
			'mark'      => 'company_logo.png',
			'markpos'   => 'bottom-right',
			'markw'     => '20%',
			'markfit'   => 'contain',
			'markpad'   => '20',
			'markalpha' => '75',
		];

		$this->assertEquals($expected, $array);
	}

	public function testWatermarkWithSpecialCharacters(): void
	{
		$watermark = new Watermark(
			mark: 'watermark-with_special.chars.png',
			markpos: 'center'
		);

		$this->assertEquals('watermark-with_special.chars.png', $watermark->mark);
		$this->assertFalse($watermark->isEmpty());
	}
}
