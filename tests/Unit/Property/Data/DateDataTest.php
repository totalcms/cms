<?php

declare(strict_types = 1);

namespace Tests\Unit\Property\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DateData;

final class DateDataTest extends TestCase
{
	public function testConstructorWithEmptyDate(): void
	{
		$dateData = new DateData();

		$this->assertEquals('', $dateData->date);
		$this->assertEquals([], $dateData->settings);
	}

	public function testConstructorWithValidDate(): void
	{
		$dateData = new DateData('2024-01-15');

		$this->assertNotEmpty($dateData->date);
		$this->assertStringContainsString('2024-01-15', $dateData->date);
	}

	public function testConstructorWithSettings(): void
	{
		$settings = ['onCreate' => true, 'readonly' => true];
		$dateData = new DateData('2024-01-15', $settings);

		$this->assertEquals($settings, $dateData->settings);
		$this->assertStringContainsString('2024-01-15', $dateData->date);
	}

	public function testCreationDateConstant(): void
	{
		$this->assertEquals('onCreate', DateData::CREATION_DATE);
	}

	public function testUpdateDateConstant(): void
	{
		$this->assertEquals('onUpdate', DateData::UPDATE_DATE);
	}

	public function testDefaultValueWithValidDate(): void
	{
		$result = DateData::defaultValue('2024-01-01', '2024-12-31');

		$this->assertIsString($result);
		$this->assertStringContainsString('2024-01-01', $result);
	}

	public function testDefaultValueWithEmptyValue(): void
	{
		$result = DateData::defaultValue('', '2024-12-31');

		$this->assertEquals('', $result);
	}

	public function testDefaultValueWithNullValue(): void
	{
		$result = DateData::defaultValue(null, '2024-12-31');

		$this->assertEquals('', $result);
	}

	public function testCleanDateWithEmptyString(): void
	{
		$result = DateData::cleanDate('');

		$this->assertEquals('', $result);
	}

	public function testCleanDateWithNull(): void
	{
		$result = DateData::cleanDate(null);

		$this->assertEquals('', $result);
	}

	public function testCleanDateWithNow(): void
	{
		$result = DateData::cleanDate('now');

		$this->assertNotEmpty($result);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result);
	}

	public function testCleanDateWithSpecificDate(): void
	{
		$result = DateData::cleanDate('2024-01-15 10:30:00');

		$this->assertNotEmpty($result);
		$this->assertStringContainsString('2024-01-15', $result);
		$this->assertStringContainsString('10:30:00', $result);
	}

	public function testCleanDateWithNaturalLanguage(): void
	{
		$result = DateData::cleanDate('yesterday');

		$this->assertNotEmpty($result);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result);
	}

	public function testCleanDateWithCustomFormat(): void
	{
		$result = DateData::cleanDate('2024-01-15', 'Y-m-d');

		$this->assertEquals('2024-01-15', $result);
	}

	public function testCleanDateWithInvalidDate(): void
	{
		$result = DateData::cleanDate('invalid-date-string');

		$this->assertEquals('', $result);
	}

	public function testTransformReturnsString(): void
	{
		$dateData = new DateData('2024-01-15');
		$result   = $dateData->transform();

		$this->assertIsString($result);
		$this->assertStringContainsString('2024-01-15', $result);
	}

	public function testToStringReturnsDate(): void
	{
		$dateData = new DateData('2024-01-15T10:30:00+00:00');
		$result   = (string)$dateData;

		$this->assertIsString($result);
		$this->assertStringContainsString('2024-01-15', $result);
	}

	public function testToStringWithEmptyDate(): void
	{
		$dateData = new DateData('');
		$result   = (string)$dateData;

		$this->assertEquals('', $result);
	}

	public function testISODateFormatDefault(): void
	{
		$dateData = new DateData('2024-01-15 15:30:45');

		// Default format should be ISO 8601 (c format)
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $dateData->date);
	}

	public function testTimezoneHandling(): void
	{
		$dateData = new DateData('2024-01-15 12:00:00 UTC');

		$this->assertNotEmpty($dateData->date);
		$this->assertStringContainsString('2024-01-15', $dateData->date);
		$this->assertStringContainsString('12:00:00', $dateData->date);
	}

	public function testEdgeCaseDates(): void
	{
		// Test leap year
		$leapYear = new DateData('2024-02-29');
		$this->assertStringContainsString('2024-02-29', $leapYear->date);

		// Test year boundaries
		$yearBoundary = new DateData('2023-12-31 23:59:59');
		$this->assertStringContainsString('2023-12-31', $yearBoundary->date);
		$this->assertStringContainsString('23:59:59', $yearBoundary->date);

		// Test start of epoch (if supported)
		$startEpoch = new DateData('1970-01-01');
		$this->assertStringContainsString('1970-01-01', $startEpoch->date);
	}

	public function testDateDataWithSpecialSettings(): void
	{
		$settings = [
			DateData::CREATION_DATE => true,
			DateData::UPDATE_DATE   => false,
			'customSetting'         => 'value',
		];

		$dateData = new DateData('2024-01-15', $settings);

		$this->assertEquals($settings, $dateData->settings);
		$this->assertTrue($dateData->settings[DateData::CREATION_DATE]);
		$this->assertFalse($dateData->settings[DateData::UPDATE_DATE]);
		$this->assertEquals('value', $dateData->settings['customSetting']);
	}

	public function testMultipleDateFormats(): void
	{
		// ISO format
		$iso = new DateData('2024-01-15T10:30:00Z');
		$this->assertNotEmpty($iso->date);

		// US format (Chronos supports this)
		$us = new DateData('01/15/2024');
		$this->assertNotEmpty($us->date);
		$this->assertStringContainsString('2024', $us->date);

		// European format (not supported by Chronos, should return empty)
		$european = new DateData('15/01/2024');
		$this->assertEquals('', $european->date);
	}
}
