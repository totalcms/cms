<?php

declare(strict_types=1);

use TotalCMS\Domain\Schema\Data\SchemaData;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

function totalcmsUsedFields(): array
{
	$data   = json_decode((string)file_get_contents(reservedSchemaPath() . 'totalcms.json'), true);
	$fields = [];
	foreach ($data['properties'] as $prop) {
		if (isset($prop['field'])) {
			$fields[$prop['field']] = true;
		}
	}

	return array_keys($fields);
}

describe('totalcms schema completeness', function (): void {
	test('every built-in field type appears at least once', function (): void {
		// Field-type aliases (e.g. multicheckbox → checklist) don't need their own
		// demo entry — only canonical types must appear in the reference schema.
		$expected = array_values(array_filter(
			array_keys(TotalCMS\Domain\Admin\TotalForm::FIELD_DEFAULT_TYPE),
			fn (string $type): bool => TotalCMS\Domain\Admin\TotalForm::canonicalFieldType($type) === $type,
		));
		$used     = totalcmsUsedFields();
		$missing  = array_values(array_diff($expected, $used));
		expect($missing)->toBe([], 'totalcms.json is missing these field types: ' . implode(', ', $missing));
	});

	test('the settings demonstrations are present', function (): void {
		$raw = (string)file_get_contents(reservedSchemaPath() . 'totalcms.json');
		foreach (['"autogen"', '"calc"', '"hide"', '"visibility"', '"required"'] as $needle) {
			expect(str_contains($raw, $needle))->toBeTrue("totalcms.json should demonstrate the {$needle} setting");
		}
	});
});

describe('totalcms schema', function (): void {
	test('totalcms.json exists, is valid JSON, and points its card/deck at totalcms-item', function (): void {
		$path = reservedSchemaPath() . 'totalcms.json';
		expect(is_readable($path))->toBeTrue();
		$data = json_decode((string)file_get_contents($path), true);
		expect($data)->toBeArray();
		expect($data['id'])->toBe('totalcms');
		expect($data['$id'])->toBe('https://www.totalcms.co/schemas/totalcms.json');

		$schemarefs = [];
		foreach ($data['properties'] as $prop) {
			if (isset($prop['schemaref'])) {
				$schemarefs[] = $prop['schemaref'];
			}
		}
		expect($schemarefs)->toContain('https://www.totalcms.co/schemas/totalcms-item.json');
	});
});

describe('totalcms-item schema', function (): void {
	test('totalcms-item.json exists and is valid JSON', function (): void {
		$path = reservedSchemaPath() . 'totalcms-item.json';
		expect(is_readable($path))->toBeTrue();
		$data = json_decode((string)file_get_contents($path), true);
		expect($data)->toBeArray();
		expect($data['id'])->toBe('totalcms-item');
		expect($data['$id'])->toBe('https://www.totalcms.co/schemas/totalcms-item.json');
		expect($data['properties'])->toBeArray()->not->toBeEmpty();
	});
});

describe('totalcms reference schema registration', function (): void {
	test('both reference schemas are reserved', function (): void {
		expect(SchemaData::RESERVED_SCHEMAS)->toContain('totalcms');
		expect(SchemaData::RESERVED_SCHEMAS)->toContain('totalcms-item');
	});

	test('REFERENCE_SCHEMAS lists exactly the two reference schemas', function (): void {
		expect(SchemaData::REFERENCE_SCHEMAS)->toBe(['totalcms', 'totalcms-item']);
	});

	test('isReferenceSchema flags reference schemas and nothing else', function (): void {
		expect(SchemaData::isReferenceSchema('totalcms'))->toBeTrue();
		expect(SchemaData::isReferenceSchema('totalcms-item'))->toBeTrue();
		expect(SchemaData::isReferenceSchema('blog'))->toBeFalse();
		expect(SchemaData::isReferenceSchema('automations'))->toBeFalse();
	});
});

describe('default-collections picker excludes reference schemas', function (): void {
	test('the picker template filters out reference schemas', function (): void {
		$twig = (string)file_get_contents(
			reservedTemplatePath() . 'admin/utils/actions/default-collections.twig'
		);
		expect($twig)->toContain('"totalcms"');
		expect($twig)->toContain('"totalcms-item"');
	});
});
