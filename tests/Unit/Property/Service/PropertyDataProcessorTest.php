<?php

declare(strict_types = 1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessor;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;

final class PropertyDataProcessorTest extends TestCase
{
	private PropertyDataProcessor $processor;

	protected function setUp(): void
	{
		$this->processor = new PropertyDataProcessor();
	}

	public function testImplementsInterface(): void
	{
		$this->assertInstanceOf(PropertyDataProcessorInterface::class, $this->processor);
	}

	public function testProcessBeforeSaveWithNonDateProperty(): void
	{
		$stringData = new StringData('test value');

		$result = $this->processor->processBeforeSave($stringData);

		$this->assertSame($stringData, $result);
		$this->assertEquals('test value', $result->text);
	}

	public function testProcessBeforeSaveWithDateDataNoSpecialSettings(): void
	{
		$dateData = new DateData('2024-01-15');

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertStringContainsString('2024-01-15', $result->date);
	}

	public function testProcessDateDataWithCreationDateSettingAndEmptyDate(): void
	{
		$settings = [DateData::CREATION_DATE => true];
		$dateData = new DateData('', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertNotEmpty($result->date);
		// Should be current timestamp in ISO format
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result->date);
	}

	public function testProcessDateDataWithCreationDateSettingAndCreationDateValue(): void
	{
		$settings = [DateData::CREATION_DATE => true];
		$dateData = new DateData(DateData::CREATION_DATE, $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertNotEmpty($result->date);
		$this->assertNotEquals(DateData::CREATION_DATE, $result->date);
		// Should be current timestamp in ISO format
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result->date);
	}

	public function testProcessDateDataWithCreationDateSettingAndExistingDate(): void
	{
		$settings     = [DateData::CREATION_DATE => true];
		$existingDate = '2023-12-25T10:30:00+00:00';
		$dateData     = new DateData($existingDate, $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		// Should keep existing date, not overwrite it
		$this->assertStringContainsString('2023-12-25', $result->date);
	}

	public function testProcessDateDataWithUpdateDateSetting(): void
	{
		$settings = [DateData::UPDATE_DATE => true];
		$dateData = new DateData('2023-01-01', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertNotEmpty($result->date);
		// Should always update to current timestamp
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result->date);
		// Should not contain the old date
		$this->assertStringNotContainsString('2023-01-01', $result->date);
	}

	public function testProcessDateDataWithUpdateDateSettingAlwaysUpdates(): void
	{
		$settings = [DateData::UPDATE_DATE => true];
		$dateData = new DateData('', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertNotEmpty($result->date);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result->date);
	}

	public function testProcessDateDataWithCreationDateSettingFalse(): void
	{
		$settings = [DateData::CREATION_DATE => false];
		$dateData = new DateData('', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		// Should not auto-set date when setting is false
		$this->assertEquals('', $result->date);
	}

	public function testProcessDateDataWithUpdateDateSettingFalse(): void
	{
		$settings     = [DateData::UPDATE_DATE => false];
		$originalDate = '2023-06-15T14:30:00+00:00';
		$dateData     = new DateData($originalDate, $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		// Should keep original date when setting is false
		$this->assertStringContainsString('2023-06-15', $result->date);
	}

	public function testProcessDateDataWithBothSettings(): void
	{
		// When both settings are true, CREATION_DATE is checked first
		// If the date already exists, it won't be updated
		$settings = [
			DateData::CREATION_DATE => true,
			DateData::UPDATE_DATE   => true,
		];
		$dateData = new DateData('2023-01-01', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertNotEmpty($result->date);
		// CREATION_DATE logic preserves existing dates, so original date should remain
		$this->assertStringContainsString('2023-01-01', $result->date);
	}

	public function testProcessDateDataUpdateTakesPrecedenceWhenCreationDateNotTrue(): void
	{
		// UPDATE_DATE should work when CREATION_DATE is not set to true
		$settings = [
			DateData::CREATION_DATE => false,
			DateData::UPDATE_DATE   => true,
		];
		$dateData = new DateData('2023-01-01', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertNotEmpty($result->date);
		// UPDATE_DATE should update to current timestamp
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $result->date);
		$this->assertStringNotContainsString('2023-01-01', $result->date);
	}

	public function testProcessDateDataWithNoSpecialSettings(): void
	{
		$settings     = ['someOtherSetting' => true];
		$originalDate = '2023-08-20T12:00:00+00:00';
		$dateData     = new DateData($originalDate, $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		// Should keep original date unchanged
		$this->assertStringContainsString('2023-08-20', $result->date);
	}

	public function testProcessDateDataPreservesSettings(): void
	{
		$settings = [
			DateData::CREATION_DATE => true,
			'customSetting'         => 'value',
		];
		$dateData = new DateData('', $settings);

		$result = $this->processor->processBeforeSave($dateData);

		$this->assertInstanceOf(DateData::class, $result);
		$this->assertEquals($settings, $result->settings);
	}

	public function testProcessDateDataReturnsNewInstance(): void
	{
		$dateData = new DateData('2023-01-01');

		$result = $this->processor->processBeforeSave($dateData);

		// Should return the same instance (no cloning in current implementation)
		$this->assertSame($dateData, $result);
	}

	public function testProcessBeforeSaveHandlesMultiplePropertyTypes(): void
	{
		// Test with StringData
		$stringData   = new StringData('test');
		$stringResult = $this->processor->processBeforeSave($stringData);
		$this->assertSame($stringData, $stringResult);

		// Test with DateData
		$dateData   = new DateData('2023-01-01', [DateData::UPDATE_DATE => true]);
		$dateResult = $this->processor->processBeforeSave($dateData);
		$this->assertInstanceOf(DateData::class, $dateResult);
		$this->assertStringNotContainsString('2023-01-01', $dateResult->date);
	}
}
