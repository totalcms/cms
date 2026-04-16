<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\License\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;

final class EditionFeatureTest extends TestCase
{
	public function testBlogSchemaLabel(): void
	{
		$this->assertSame('Blog Schema', EditionFeature::BLOG_SCHEMA->label());
	}

	public function testDepotSchemaLabel(): void
	{
		$this->assertSame('Depot Schema', EditionFeature::DEPOT_SCHEMA->label());
	}

	public function testCustomSchemasLabel(): void
	{
		$this->assertSame('Custom Schemas', EditionFeature::CUSTOM_SCHEMAS->label());
	}

	public function testImageWatermarksLabel(): void
	{
		$this->assertSame('Image Watermarks', EditionFeature::IMAGE_WATERMARKS->label());
	}

	public function testTextWatermarksLabel(): void
	{
		$this->assertSame('Text Watermarks', EditionFeature::TEXT_WATERMARKS->label());
	}

	public function testMailerActionsLabel(): void
	{
		$this->assertSame('Mailer Form Actions', EditionFeature::MAILER_ACTIONS->label());
	}

	public function testWebhookActionsLabel(): void
	{
		$this->assertSame('Webhook Form Actions', EditionFeature::WEBHOOK_ACTIONS->label());
	}

	public function testExternalRestApiLabel(): void
	{
		$this->assertSame('External REST API', EditionFeature::EXTERNAL_REST_API->label());
	}

	public function testQrCodesLabel(): void
	{
		$this->assertSame('QR Codes', EditionFeature::QR_CODES->label());
	}

	public function testBarcodesLabel(): void
	{
		$this->assertSame('Barcodes', EditionFeature::BARCODES->label());
	}

	public function testTemplatesLabel(): void
	{
		$this->assertSame('Templates', EditionFeature::TEMPLATES->label());
	}

	public function testWhitelabelTemplatesLabel(): void
	{
		$this->assertSame('Whitelabel Templates', EditionFeature::WHITELABEL_TEMPLATES->label());
	}

	public function testAccessGroupsLabel(): void
	{
		$this->assertSame('Access Groups', EditionFeature::ACCESS_GROUPS->label());
	}

	public function testApiKeysLabel(): void
	{
		$this->assertSame('API Keys', EditionFeature::API_KEYS->label());
	}

	public function testStandardFeatureRequirements(): void
	{
		$this->assertSame(Edition::STANDARD, EditionFeature::BLOG_SCHEMA->requiredEdition());
		$this->assertSame(Edition::STANDARD, EditionFeature::DEPOT_SCHEMA->requiredEdition());
		$this->assertSame(Edition::STANDARD, EditionFeature::IMAGE_WATERMARKS->requiredEdition());
		$this->assertSame(Edition::STANDARD, EditionFeature::MAILER_ACTIONS->requiredEdition());
		$this->assertSame(Edition::STANDARD, EditionFeature::QR_CODES->requiredEdition());
		$this->assertSame(Edition::STANDARD, EditionFeature::TEMPLATES->requiredEdition());
		$this->assertSame(Edition::STANDARD, EditionFeature::ACCESS_GROUPS->requiredEdition());
	}

	public function testProFeatureRequirements(): void
	{
		$this->assertSame(Edition::PRO, EditionFeature::CUSTOM_SCHEMAS->requiredEdition());
		$this->assertSame(Edition::PRO, EditionFeature::TEXT_WATERMARKS->requiredEdition());
		$this->assertSame(Edition::PRO, EditionFeature::WEBHOOK_ACTIONS->requiredEdition());
		$this->assertSame(Edition::PRO, EditionFeature::EXTERNAL_REST_API->requiredEdition());
		$this->assertSame(Edition::PRO, EditionFeature::BARCODES->requiredEdition());
		$this->assertSame(Edition::PRO, EditionFeature::WHITELABEL_TEMPLATES->requiredEdition());
		$this->assertSame(Edition::PRO, EditionFeature::API_KEYS->requiredEdition());
	}

	public function testAllFeaturesHaveLabels(): void
	{
		foreach (EditionFeature::cases() as $feature) {
			$this->assertNotEmpty($feature->label());
		}
	}

	public function testAllFeaturesHaveRequiredEditions(): void
	{
		foreach (EditionFeature::cases() as $feature) {
			$edition = $feature->requiredEdition();
			$this->assertInstanceOf(Edition::class, $edition);
		}
	}

	public function testFeatureValues(): void
	{
		$this->assertSame('blog_schema', EditionFeature::BLOG_SCHEMA->value);
		$this->assertSame('depot_schema', EditionFeature::DEPOT_SCHEMA->value);
		$this->assertSame('custom_schemas', EditionFeature::CUSTOM_SCHEMAS->value);
		$this->assertSame('image_watermarks', EditionFeature::IMAGE_WATERMARKS->value);
		$this->assertSame('text_watermarks', EditionFeature::TEXT_WATERMARKS->value);
		$this->assertSame('mailer_actions', EditionFeature::MAILER_ACTIONS->value);
		$this->assertSame('webhook_actions', EditionFeature::WEBHOOK_ACTIONS->value);
		$this->assertSame('external_rest_api', EditionFeature::EXTERNAL_REST_API->value);
		$this->assertSame('qr_codes', EditionFeature::QR_CODES->value);
		$this->assertSame('barcodes', EditionFeature::BARCODES->value);
		$this->assertSame('templates', EditionFeature::TEMPLATES->value);
		$this->assertSame('whitelabel_templates', EditionFeature::WHITELABEL_TEMPLATES->value);
		$this->assertSame('access_groups', EditionFeature::ACCESS_GROUPS->value);
		$this->assertSame('api_keys', EditionFeature::API_KEYS->value);
	}

	public function testAllFeaturesHaveUniqueValues(): void
	{
		$values = [];
		foreach (EditionFeature::cases() as $feature) {
			$this->assertNotContains($feature->value, $values);
			$values[] = $feature->value;
		}
	}
}
