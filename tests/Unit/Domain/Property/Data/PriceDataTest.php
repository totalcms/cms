<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property\Data;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\PriceData;

/**
 * Tests for PriceData — currency-aware price normalization.
 *
 * PriceData extends NumberData and normalizes a possibly-formatted price string
 * (e.g. "$100,000.00", "100.000,00 €", "¥100,000") to a float WITHOUT a locale.
 */
#[CoversClass(PriceData::class)]
final class PriceDataTest extends TestCase
{
	// -------------------------------------------------------------------------
	// Scalar inputs (int/float passthrough)
	// -------------------------------------------------------------------------

	public function testIntegerInput(): void
	{
		$this->assertSame(100000.0, (new PriceData(100000))->transform());
	}

	public function testFloatInput(): void
	{
		$this->assertSame(100.5, (new PriceData(100.5))->transform());
	}

	// -------------------------------------------------------------------------
	// Plain numeric strings
	// -------------------------------------------------------------------------

	public function testPlainNumericString(): void
	{
		$this->assertSame(100000.0, (new PriceData('100000'))->transform());
	}

	// -------------------------------------------------------------------------
	// Formatted currency strings
	// -------------------------------------------------------------------------

	public function testDollarSignWithCommaAndDecimal(): void
	{
		// "$100,000.00" — both separators present, dot is rightmost → decimal
		$this->assertSame(100000.0, (new PriceData('$100,000.00'))->transform());
	}

	public function testCommaThousandsWithDotDecimal(): void
	{
		// "100,000.00" — both separators, dot rightmost → decimal
		$this->assertSame(100000.0, (new PriceData('100,000.00'))->transform());
	}

	public function testDotThousandsWithCommaDecimal(): void
	{
		// "100.000,00" — de-DE style: dot = thousands, comma = decimal
		$this->assertSame(100000.0, (new PriceData('100.000,00'))->transform());
	}

	public function testDotThousandsWithCommaDecimalAndEuroSign(): void
	{
		// "100.000,00 €" — currency symbol stripped before inference
		$this->assertSame(100000.0, (new PriceData('100.000,00 €'))->transform());
	}

	public function testYenSignWithCommaThousands(): void
	{
		// "¥100,000" — single comma, 3 trailing digits → thousands
		$this->assertSame(100000.0, (new PriceData('¥100,000'))->transform());
	}

	public function testSingleCommaThreeTrailingDigits(): void
	{
		// "100,000" — single comma, exactly 3 digits after → thousands
		$this->assertSame(100000.0, (new PriceData('100,000'))->transform());
	}

	public function testSingleCommaTwoTrailingDigits(): void
	{
		// "100,50" — single comma, 2 trailing digits → decimal point
		$this->assertSame(100.5, (new PriceData('100,50'))->transform());
	}

	public function testSingleDotTwoTrailingDigits(): void
	{
		// "100.50" — single dot, 2 trailing digits → decimal point
		$this->assertSame(100.5, (new PriceData('100.50'))->transform());
	}

	public function testMultipleCommasAreThousandsSeparators(): void
	{
		// "1,000,000" — multiple commas → all are thousands grouping
		$this->assertSame(1000000.0, (new PriceData('1,000,000'))->transform());
	}

	public function testMultipleDotsAreThousandsSeparators(): void
	{
		// "1.000.000" — multiple dots → all are thousands grouping (de-DE style)
		$this->assertSame(1000000.0, (new PriceData('1.000.000'))->transform());
	}

	public function testSingleDotThreeTrailingDigitsIsThousands(): void
	{
		// "1.000" — the documented ambiguous case: single dot, exactly 3 trailing
		// digits → treated as thousands (1000), not 1.0. Locale-free heuristic
		// picks the common price intent.
		$this->assertSame(1000.0, (new PriceData('1.000'))->transform());
	}

	public function testIndianGroupingMultipleCommas(): void
	{
		// "1,00,000" — Indian grouping (multiple commas) → thousands, strip all
		$this->assertSame(100000.0, (new PriceData('1,00,000'))->transform());
	}

	// -------------------------------------------------------------------------
	// Edge cases
	// -------------------------------------------------------------------------

	public function testEmptyStringReturnsZero(): void
	{
		$this->assertSame(0.0, (new PriceData(''))->transform());
	}

	public function testNegativeValue(): void
	{
		$this->assertSame(-50.25, (new PriceData('-50.25'))->transform());
	}

	public function testDefaultConstructorReturnsZero(): void
	{
		$this->assertSame(0.0, (new PriceData())->transform());
	}

	// -------------------------------------------------------------------------
	// Return type
	// -------------------------------------------------------------------------

	public function testTransformReturnsFloat(): void
	{
		$result = (new PriceData('100.00'))->transform();
		$this->assertIsFloat($result);
	}
}
