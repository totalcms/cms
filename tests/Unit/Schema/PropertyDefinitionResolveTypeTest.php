<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;

/**
 * Verifies that `resolveType()` produces a useful type name for the schema
 * editor's type dropdown across the resolution chain:
 *   1. Canonical $ref → reverse-lookup against PROPERTY_TYPE_TO_REF.
 *   2. Custom $ref whose basename happens to be a known type.
 *   3. Custom $ref whose basename is NOT a known type → fall back to `field`.
 *      This is the card case: `expandCardProperty` used to overwrite $ref
 *      with the user's sub-schema, so basename was the user's filename
 *      (e.g. `my-card`) rather than the canonical type.
 */
#[CoversClass(PropertyDefinition::class)]
final class PropertyDefinitionResolveTypeTest extends TestCase
{
	public function testCanonicalImageRefReverseMapsToImage(): void
	{
		$prop = PropertyDefinition::fromArray([
			'$ref'  => 'https://www.totalcms.co/schemas/properties/image.json',
			'field' => 'image',
		]);
		expect($prop->resolveType())->toBe('image');
	}

	public function testCanonicalCardRefReverseMapsToCard(): void
	{
		$prop = PropertyDefinition::fromArray([
			'$ref'  => 'https://www.totalcms.co/schemas/properties/card.json',
			'field' => 'card',
		]);
		expect($prop->resolveType())->toBe('card');
	}

	public function testCanonicalDeckRefReverseMapsToDeck(): void
	{
		$prop = PropertyDefinition::fromArray([
			'$ref'  => 'https://www.totalcms.co/schemas/properties/deck.json',
			'field' => 'deck',
		]);
		expect($prop->resolveType())->toBe('deck');
	}

	public function testCustomRefWithKnownBasenameReturnsBasename(): void
	{
		// `string.json` isn't in PROPERTY_TYPE_TO_REF but `string` is in PROPERTY_TYPES.
		$prop = PropertyDefinition::fromArray([
			'$ref'  => 'https://example.com/schemas/string.json',
			'field' => 'text',
		]);
		expect($prop->resolveType())->toBe('string');
	}

	public function testCustomRefWithUnknownBasenameFallsBackToField(): void
	{
		// Card whose $ref was rewritten to the user's sub-schema (legacy data).
		// Basename `my-card` is not a real type — fall through to `field`.
		$prop = PropertyDefinition::fromArray([
			'$ref'  => 'https://www.totalcms.co/schemas/custom/my-card.json',
			'field' => 'card',
		]);
		expect($prop->resolveType())->toBe('card');
	}

	public function testNoRefFallsBackToType(): void
	{
		$prop = PropertyDefinition::fromArray([
			'type'  => 'string',
			'field' => 'text',
		]);
		expect($prop->resolveType())->toBe('string');
	}

	public function testNoRefAndNoTypeFallsBackToField(): void
	{
		$prop = PropertyDefinition::fromArray([
			'field' => 'text',
		]);
		expect($prop->resolveType())->toBe('text');
	}

	public function testIntegerIsAResolvedTypeInItsOwnRight(): void
	{
		// JSON Schema's whole-number type; recognised so the schema editor's
		// dropdown and the schema page's icons know it rather than fall through.
		$prop = PropertyDefinition::fromArray([
			'type'  => 'integer',
			'field' => 'number',
		]);
		expect($prop->resolveType())->toBe('integer');
	}

	public function testJsonSchemaListTypeResolvesToItsFirstNonNullEntry(): void
	{
		// `"type": ["string", "null"]` is valid JSON Schema and SchemaSaver passes
		// it through. Casting the list to string warned "Array to string
		// conversion", which the error handler turned into a crash of
		// jumpstart:import and schema:lint (TOTAL-CMS-QE / QF).
		$prop = PropertyDefinition::fromArray([
			'type'  => ['null', 'string'],
			'field' => 'text',
		]);
		expect($prop->type)->toBe('string');
		expect($prop->resolveType())->toBe('string');

		$onlyNull = PropertyDefinition::fromArray([
			'type'  => ['null'],
			'field' => 'text',
		]);
		expect($onlyNull->type)->toBeNull();
		expect($onlyNull->resolveType())->toBe('text');
	}

	public function testEffectiveJsonTypeOfARefListIsArray(): void
	{
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://www.totalcms.co/schemas/properties/list.json', 'field' => 'list']);
		expect($prop->effectiveJsonType())->toBe('array');
	}

	public function testEffectiveJsonTypeOfARefImageIsObject(): void
	{
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image']);
		expect($prop->effectiveJsonType())->toBe('object');
	}

	public function testEffectiveJsonTypeOfARefDeckIsObject(): void
	{
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck']);
		expect($prop->effectiveJsonType())->toBe('object');
	}

	public function testEffectiveJsonTypeOfARefColorIsObject(): void
	{
		// color.json is an anyOf of objects and ColorData::transform() returns an array.
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://www.totalcms.co/schemas/properties/color.json', 'field' => 'color']);
		expect($prop->effectiveJsonType())->toBe('object');
	}

	public function testEffectiveJsonTypeOfARefSlugIsString(): void
	{
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id']);
		expect($prop->effectiveJsonType())->toBe('string');
	}

	public function testEffectiveJsonTypeOfAFormatOnlyRefIsString(): void
	{
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://www.totalcms.co/schemas/properties/email.json', 'field' => 'email']);
		expect($prop->effectiveJsonType())->toBe('string');
	}

	public function testEffectiveJsonTypeOfACustomCardRefFallsBackToTheField(): void
	{
		$prop = PropertyDefinition::fromArray(['$ref' => 'https://example.com/schemas/my-card.json', 'field' => 'card']);
		expect($prop->effectiveJsonType())->toBe('object');
	}

	public function testEffectiveJsonTypePrefersAnExplicitType(): void
	{
		$prop = PropertyDefinition::fromArray(['type' => 'boolean', '$ref' => 'https://www.totalcms.co/schemas/properties/list.json']);
		expect($prop->effectiveJsonType())->toBe('boolean');
	}

	public function testEffectiveJsonTypeIsNullWithNeitherTypeNorRef(): void
	{
		expect(PropertyDefinition::fromArray(['field' => 'list'])->effectiveJsonType())->toBeNull();
		expect(PropertyDefinition::fromArray([])->effectiveJsonType())->toBeNull();
	}
}
