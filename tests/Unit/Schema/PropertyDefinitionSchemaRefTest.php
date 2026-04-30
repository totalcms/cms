<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;

/**
 * Verifies that `schemaref` is the canonical schema-reference key on properties
 * and that the legacy `deckref` alias is accepted at read time but normalized
 * to `schemaref` on output.
 */
#[CoversClass(PropertyDefinition::class)]
final class PropertyDefinitionSchemaRefTest extends TestCase
{
	public function testExtractReadsSchemaRefAtTopLevel(): void
	{
		$ref = PropertyDefinition::extractSchemaRef([
			'schemaref' => 'https://example.com/schemas/foo.json',
		]);
		expect($ref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testExtractReadsLegacyDeckRefAtTopLevel(): void
	{
		$ref = PropertyDefinition::extractSchemaRef([
			'deckref' => 'https://example.com/schemas/foo.json',
		]);
		expect($ref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testExtractReadsSchemaRefFromSettings(): void
	{
		$ref = PropertyDefinition::extractSchemaRef([
			'settings' => ['schemaref' => 'https://example.com/schemas/foo.json'],
		]);
		expect($ref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testExtractReadsLegacyDeckRefFromSettings(): void
	{
		$ref = PropertyDefinition::extractSchemaRef([
			'settings' => ['deckref' => 'https://example.com/schemas/foo.json'],
		]);
		expect($ref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testExtractPrefersSchemaRefWhenBothPresent(): void
	{
		$ref = PropertyDefinition::extractSchemaRef([
			'schemaref' => 'https://example.com/new.json',
			'deckref'   => 'https://example.com/legacy.json',
		]);
		expect($ref)->toBe('https://example.com/new.json');
	}

	public function testExtractReturnsNullWhenAbsent(): void
	{
		expect(PropertyDefinition::extractSchemaRef([]))->toBeNull();
		expect(PropertyDefinition::extractSchemaRef(['settings' => []]))->toBeNull();
	}

	public function testExtractReturnsNullForEmptyString(): void
	{
		$ref = PropertyDefinition::extractSchemaRef(['schemaref' => '']);
		expect($ref)->toBeNull();
	}

	public function testFromArrayReadsSchemaRef(): void
	{
		$def = PropertyDefinition::fromArray([
			'field'     => 'deck',
			'schemaref' => 'https://example.com/schemas/foo.json',
		]);
		expect($def->schemaref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testFromArrayReadsLegacyDeckRef(): void
	{
		$def = PropertyDefinition::fromArray([
			'field'   => 'deck',
			'deckref' => 'https://example.com/schemas/foo.json',
		]);
		expect($def->schemaref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testLegacyDeckRefPropertyAccessStillWorks(): void
	{
		$def = PropertyDefinition::fromArray([
			'field'     => 'deck',
			'schemaref' => 'https://example.com/schemas/foo.json',
		]);
		// Backward-compat read via $def->deckref returns the same value
		expect($def->deckref)->toBe('https://example.com/schemas/foo.json');
	}

	public function testToArrayEmitsSchemaRefAsCanonical(): void
	{
		$def = PropertyDefinition::fromArray([
			'field'   => 'deck',
			'deckref' => 'https://example.com/schemas/foo.json',
		]);
		$out = $def->toArray();
		expect($out)->toHaveKey('schemaref');
		expect($out['schemaref'])->toBe('https://example.com/schemas/foo.json');
		expect($out)->not->toHaveKey('deckref');
	}

	public function testRoundTripDropsDuplicateLegacyKey(): void
	{
		// Schema with both keys present should output only `schemaref`
		$def = PropertyDefinition::fromArray([
			'field'     => 'deck',
			'schemaref' => 'https://example.com/preferred.json',
			'deckref'   => 'https://example.com/legacy.json',
		]);
		$out = $def->toArray();
		expect($out)->toHaveKey('schemaref');
		expect($out['schemaref'])->toBe('https://example.com/preferred.json');
		expect($out)->not->toHaveKey('deckref');
	}

	public function testNoSchemaRefEmittedWhenAbsent(): void
	{
		$def = PropertyDefinition::fromArray(['field' => 'text']);
		$out = $def->toArray();
		expect($out)->not->toHaveKey('schemaref');
		expect($out)->not->toHaveKey('deckref');
	}
}
