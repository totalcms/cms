<?php

namespace Tests\Unit\Domain\License\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Data\Edition;

final class EditionTest extends TestCase
{
	public function testLiteLevel(): void
	{
		$this->assertSame(1, Edition::LITE->level());
	}

	public function testStandardLevel(): void
	{
		$this->assertSame(2, Edition::STANDARD->level());
	}

	public function testProLevel(): void
	{
		$this->assertSame(3, Edition::PRO->level());
	}

	public function testEnterpriseLevel(): void
	{
		$this->assertSame(3, Edition::ENTERPRISE->level());
	}

	public function testDevelopmentLevel(): void
	{
		$this->assertSame(3, Edition::DEVELOPMENT->level());
	}

	public function testTrialLevel(): void
	{
		$this->assertSame(3, Edition::TRIAL->level());
	}

	public function testUnknownLevel(): void
	{
		$this->assertSame(0, Edition::UNKNOWN->level());
	}

	public function testHasLevelForLite(): void
	{
		$this->assertTrue(Edition::LITE->hasLevel(1));
		$this->assertFalse(Edition::LITE->hasLevel(2));
		$this->assertFalse(Edition::LITE->hasLevel(3));
	}

	public function testHasLevelForStandard(): void
	{
		$this->assertTrue(Edition::STANDARD->hasLevel(1));
		$this->assertTrue(Edition::STANDARD->hasLevel(2));
		$this->assertFalse(Edition::STANDARD->hasLevel(3));
	}

	public function testHasLevelForPro(): void
	{
		$this->assertTrue(Edition::PRO->hasLevel(1));
		$this->assertTrue(Edition::PRO->hasLevel(2));
		$this->assertTrue(Edition::PRO->hasLevel(3));
	}

	public function testHasLevelForUnknown(): void
	{
		$this->assertTrue(Edition::UNKNOWN->hasLevel(0));
		$this->assertFalse(Edition::UNKNOWN->hasLevel(1));
	}

	public function testFromStringWithValidValue(): void
	{
		$this->assertSame(Edition::LITE, Edition::fromString('lite'));
		$this->assertSame(Edition::STANDARD, Edition::fromString('standard'));
		$this->assertSame(Edition::PRO, Edition::fromString('pro'));
	}

	public function testFromStringIsCaseInsensitive(): void
	{
		$this->assertSame(Edition::LITE, Edition::fromString('LITE'));
		$this->assertSame(Edition::STANDARD, Edition::fromString('Standard'));
		$this->assertSame(Edition::PRO, Edition::fromString('PRO'));
	}

	public function testFromStringReturnsUnknownForInvalidValue(): void
	{
		$this->assertSame(Edition::UNKNOWN, Edition::fromString('invalid'));
		$this->assertSame(Edition::UNKNOWN, Edition::fromString(''));
		$this->assertSame(Edition::UNKNOWN, Edition::fromString('premium'));
	}

	public function testAllEditionsHaveUniqueValues(): void
	{
		$values = [];
		foreach (Edition::cases() as $edition) {
			$this->assertNotContains($edition->value, $values);
			$values[] = $edition->value;
		}
	}

	public function testEditionValues(): void
	{
		$this->assertSame('lite', Edition::LITE->value);
		$this->assertSame('standard', Edition::STANDARD->value);
		$this->assertSame('pro', Edition::PRO->value);
		$this->assertSame('enterprise', Edition::ENTERPRISE->value);
		$this->assertSame('development', Edition::DEVELOPMENT->value);
		$this->assertSame('trial', Edition::TRIAL->value);
		$this->assertSame('unknown', Edition::UNKNOWN->value);
	}

	public function testLevelHierarchy(): void
	{
		$this->assertLessThan(Edition::STANDARD->level(), Edition::LITE->level());
		$this->assertLessThan(Edition::PRO->level(), Edition::STANDARD->level());
	}
}
