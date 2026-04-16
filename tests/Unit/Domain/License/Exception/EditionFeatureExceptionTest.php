<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\License\Exception;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Exception\EditionFeatureException;

final class EditionFeatureExceptionTest extends TestCase
{
	public function testConstructorWithDefaultMessage(): void
	{
		$exception = new EditionFeatureException(
			EditionFeature::CUSTOM_SCHEMAS,
			Edition::PRO,
			Edition::LITE
		);

		$this->assertStringContainsString('Custom Schemas', $exception->getMessage());
		$this->assertStringContainsString('Pro', $exception->getMessage());
		$this->assertStringContainsString('Lite', $exception->getMessage());
		$this->assertSame(403, $exception->getCode());
	}

	public function testConstructorWithCustomMessage(): void
	{
		$exception = new EditionFeatureException(
			EditionFeature::API_KEYS,
			Edition::PRO,
			Edition::STANDARD,
			'Custom error message'
		);

		$this->assertSame('Custom error message', $exception->getMessage());
	}

	public function testFeatureProperty(): void
	{
		$exception = new EditionFeatureException(
			EditionFeature::TEMPLATES,
			Edition::STANDARD,
			Edition::LITE
		);

		$this->assertSame(EditionFeature::TEMPLATES, $exception->feature);
	}

	public function testRequiredEditionProperty(): void
	{
		$exception = new EditionFeatureException(
			EditionFeature::BARCODES,
			Edition::PRO,
			Edition::STANDARD
		);

		$this->assertSame(Edition::PRO, $exception->requiredEdition);
	}

	public function testCurrentEditionProperty(): void
	{
		$exception = new EditionFeatureException(
			EditionFeature::ACCESS_GROUPS,
			Edition::STANDARD,
			Edition::LITE
		);

		$this->assertSame(Edition::LITE, $exception->currentEdition);
	}

	public function testGetUserMessage(): void
	{
		$exception = new EditionFeatureException(
			EditionFeature::QR_CODES,
			Edition::STANDARD,
			Edition::LITE
		);

		$userMessage = $exception->getUserMessage();

		$this->assertStringContainsString('Standard', $userMessage);
		$this->assertStringContainsString('QR Codes', $userMessage);
		$this->assertStringContainsString('upgrade', $userMessage);
	}

	public function testExceptionIsThrowable(): void
	{
		$this->expectException(EditionFeatureException::class);

		throw new EditionFeatureException(
			EditionFeature::MAILER_ACTIONS,
			Edition::STANDARD,
			Edition::LITE
		);
	}

	public function testAllFeaturesGenerateValidMessages(): void
	{
		foreach (EditionFeature::cases() as $feature) {
			$exception = new EditionFeatureException(
				$feature,
				Edition::PRO,
				Edition::LITE
			);

			$this->assertNotEmpty($exception->getMessage());
			$this->assertNotEmpty($exception->getUserMessage());
		}
	}
}
