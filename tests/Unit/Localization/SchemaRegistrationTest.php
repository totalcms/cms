<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Smoke tests for the wiring between SchemaData, PropertyFactory, and
 * the property JSON schemas. Confirms that 3.5 sliver lock-in points
 * are in place.
 *
 * Pro-edition gating is intentionally NOT enforced via a dedicated
 * EditionFeature — both field types live exclusively in custom schemas,
 * which are already gated behind EditionFeature::CUSTOM_SCHEMAS. The
 * transitive gate is sufficient; a dedicated one would just be redundant
 * code that fires only in unreachable paths.
 */
describe('Localization sliver wiring', function (): void {
	test('SchemaData::PROPERTY_TYPES has a single localizedtext entry', function (): void {
		expect(SchemaData::PROPERTY_TYPES)->toContain('localizedtext');
		// localizedstyledtext is NOT a separate stored type — both field
		// variants use the same `localizedtext` type. The distinction lives
		// in the `field` attribute (form rendering), not the stored type.
		expect(SchemaData::PROPERTY_TYPES)->not->toContain('localizedstyledtext');
	});

	test('SchemaData::PROPERTY_TYPE_TO_REF maps localizedtext to its property schema URL', function (): void {
		expect(SchemaData::PROPERTY_TYPE_TO_REF)->toHaveKey('localizedtext');
		expect(SchemaData::PROPERTY_TYPE_TO_REF)->not->toHaveKey('localizedstyledtext');
		expect(SchemaData::PROPERTY_TYPE_TO_REF['localizedtext'])
			->toBe('https://www.totalcms.co/schemas/properties/localizedtext.json');
	});

	test('TotalForm::FIELD_DEFAULT_TYPE collapses both field variants into the same stored type', function (): void {
		// When the admin editor saves either field variant, the schema's
		// `type` is normalized to `localizedtext` before propertyTypeToRef()
		// converts it to $ref. So both fields share the same on-disk shape.
		expect(TotalForm::getDefaultTypeForField('localizedtext'))->toBe('localizedtext');
		expect(TotalForm::getDefaultTypeForField('localizedstyledtext'))->toBe('localizedtext');
	});

	test('localized fields are deck/card compatible', function (): void {
		// Localized fields store a flat locale-keyed dict — no separate
		// filesystem state (unlike gallery/depot) and no schemaref recursion
		// (unlike nested decks), so they nest cleanly inside cards and decks.
		$checker = new TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker();

		$schema = [
			'properties' => [
				'title' => [
					'field' => 'localizedtext',
					'$ref'  => 'https://www.totalcms.co/schemas/properties/localizedtext.json',
				],
				'body' => [
					'field' => 'localizedstyledtext',
					'$ref'  => 'https://www.totalcms.co/schemas/properties/localizedtext.json',
				],
			],
		];

		expect($checker->isCompatible($schema))->toBeTrue();
		expect($checker->getIncompatibleProperties($schema))->toBe([]);
	});

	test('property JSON schema exists on disk', function (): void {
		$base = dirname(__DIR__, 3) . '/resources/schemas/properties';

		expect(file_exists("{$base}/localizedtext.json"))->toBeTrue();
		// localizedstyledtext.json was deliberately removed — both field
		// types reference localizedtext.json.
		expect(file_exists("{$base}/localizedstyledtext.json"))->toBeFalse();

		$ltText = json_decode((string)file_get_contents("{$base}/localizedtext.json"), true);
		expect($ltText)->toBeArray();
		expect($ltText['$id'])->toBe('https://www.totalcms.co/schemas/properties/localizedtext.json');
	});
});
