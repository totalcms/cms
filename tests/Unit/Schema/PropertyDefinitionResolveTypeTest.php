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
}
