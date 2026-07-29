<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * "Setup Default Collections" used to provision one collection per reserved
 * schema, which meant every schema that exists only to be embedded in a card or
 * deck — automation triggers, MCP sub-objects, sitemap meta — got a junk
 * top-level collection nobody asked for.
 *
 * The membership can't be inferred: an embedded schema and a standalone one are
 * structurally identical (both declare id/type/properties/index). So it's an
 * explicit list, and this test guards it.
 */
final class DefaultCollectionsTest extends TestCase
{
	/**
	 * Reserved schemas deliberately excluded from the default set, with the
	 * reason. Kept here rather than in production code: it exists to force a
	 * decision, not to be consumed at runtime.
	 *
	 * @var array<string,string>
	 */
	private const EXCLUDED = [
		'automation-trigger' => 'embedded in automations via schemaref',
		'mcp-collection'     => 'embedded in collection + dataviews via schemaref',
		'mcp-prompt-arg'     => 'embedded in mcp-prompt via schemaref',
		'mcp-property'       => 'embedded sub-object, never standalone',
		'mcp-tool'           => 'embedded in mcp-collection via schemaref',
		'preset-item'        => 'embedded sub-object, never standalone',
		'sitemap-meta'       => 'embedded in collection via schemaref',
		'blog-legacy'        => 'deprecated, superseded by blog',
		'builder-page'       => 'provisioned by BuilderInstaller as builder-pages',
		'totalcms'           => 'reference schema, can never back a collection',
		'totalcms-item'      => 'reference schema, can never back a collection',
	];

	public function testEveryReservedSchemaIsEitherADefaultOrDeliberatelyExcluded(): void
	{
		// The point of this assertion: adding a reserved schema without deciding
		// whether it deserves a collection fails here, instead of silently
		// provisioning one (the old bug) or silently provisioning none (the new
		// failure mode if the list is just forgotten).
		$classified = array_merge(SchemaData::DEFAULT_COLLECTIONS, array_keys(self::EXCLUDED));

		$unclassified = array_diff(SchemaData::RESERVED_SCHEMAS, $classified);

		expect($unclassified)->toBe(
			[],
			'Reserved schema(s) not classified — add to SchemaData::DEFAULT_COLLECTIONS or to EXCLUDED here with a reason: '
			. implode(', ', $unclassified),
		);
	}

	public function testDefaultsAndExclusionsDoNotOverlap(): void
	{
		expect(array_intersect(SchemaData::DEFAULT_COLLECTIONS, array_keys(self::EXCLUDED)))->toBe([]);
	}

	public function testEveryDefaultIsAReservedSchema(): void
	{
		expect(array_diff(SchemaData::DEFAULT_COLLECTIONS, SchemaData::RESERVED_SCHEMAS))->toBe([]);
	}

	public function testNoReferenceSchemaIsADefault(): void
	{
		// fetchOrCreateReserved() returns null for these anyway; listing one
		// would be a silent no-op that reads as intent.
		foreach (SchemaData::REFERENCE_SCHEMAS as $schemaId) {
			expect(SchemaData::DEFAULT_COLLECTIONS)->not->toContain($schemaId);
		}
	}

	public function testTheEmbeddedSchemasThatCausedThisAreNotDefaults(): void
	{
		foreach (['automation-trigger', 'sitemap-meta', 'mcp-collection', 'mcp-tool', 'mcp-prompt-arg', 'mcp-property', 'preset-item'] as $schemaId) {
			expect(SchemaData::DEFAULT_COLLECTIONS)->not->toContain($schemaId);
		}
	}

	public function testTheStandardContentCollectionsAreDefaults(): void
	{
		// blog/gallery/image/file are named in resources/docs/get-started; the
		// rest back admin features that expect their collection to exist.
		foreach (['blog', 'gallery', 'image', 'file', 'auth', 'depot', 'feed', 'dataviews', 'mailer', 'playground', 'automations', 'mcp-prompt'] as $schemaId) {
			expect(SchemaData::DEFAULT_COLLECTIONS)->toContain($schemaId);
		}
	}

	public function testSingleValueContentTypesRemainDefaults(): void
	{
		// Classic Total CMS content types. They double as card/deck children but
		// are legitimate standalone collections, so they stay.
		foreach (['text', 'toggle', 'date', 'number', 'color', 'url', 'email', 'code', 'styledtext', 'svg'] as $schemaId) {
			expect(SchemaData::DEFAULT_COLLECTIONS)->toContain($schemaId);
		}
	}
}
