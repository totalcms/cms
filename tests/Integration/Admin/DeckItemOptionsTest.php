<?php

declare(strict_types=1);

use TotalCMS\Domain\Schema\Service\SchemaFactory;

describe('DeckItem Options', function (): void {
	it('loads schema with options from JSON', function (): void {
		$featureSchemaJson = '{
			"id": "feature-test",
			"type": "object",
			"description": "Test feature schema",
			"properties": {
				"id": {"type": "string", "field": "id", "label": "ID"},
				"name": {"type": "string", "field": "text", "label": "Name"},
				"category": {
					"type": "string",
					"field": "select",
					"label": "Category",
					"options": [
						{"value": "performance", "label": "Performance"},
						{"value": "design", "label": "Design"}
					]
				}
			},
			"required": ["id"]
		}';

		$factory       = new SchemaFactory();
		$featureSchema = $factory->generateSchemaFromJson($featureSchemaJson);

		// Verify schema has options
		expect($featureSchema->properties['category'])->toHaveKey('options');
		expect($featureSchema->properties['category']['options'])->toHaveCount(2);
	});

	it('passes options from schema to fieldConfig', function (): void {
		$featureSchemaJson = '{
			"id": "feature-test",
			"type": "object",
			"properties": {
				"category": {
					"type": "string",
					"field": "select",
					"label": "Category",
					"options": [
						{"value": "performance", "label": "Performance"},
						{"value": "design", "label": "Design"}
					]
				}
			}
		}';

		$factory        = new SchemaFactory();
		$featureSchema  = $factory->generateSchemaFromJson($featureSchemaJson);
		$propertyName   = 'category';
		$propertySchema = $featureSchema->properties[$propertyName];

		// Simulate what DeckItem::buildSchemaBasedFields does
		$fieldConfig = [
			'field'       => $propertySchema['field'] ?? 'text',
			'label'       => $propertySchema['label'] ?? ucfirst($propertyName),
			'options'     => $propertySchema['options'] ?? [],
			'settings'    => $propertySchema['settings'] ?? [],
		];

		expect($fieldConfig['options'])->toHaveCount(2);
		expect($fieldConfig['options'][0]['value'])->toBe('performance');
		expect($fieldConfig['options'][1]['value'])->toBe('design');
	});

	it('loads real feature schema with options', function (): void {
		$schemaPath = dirname(__DIR__, 3) . '/tcms-data/.schemas/feature.json';

		if (!file_exists($schemaPath)) {
			$this->markTestSkipped('Feature schema not found');
		}

		$schemaJson = file_get_contents($schemaPath);
		$factory    = new SchemaFactory();
		$schema     = $factory->generateSchemaFromJson($schemaJson);

		expect($schema->properties)->toHaveKey('category');
		expect($schema->properties['category'])->toHaveKey('options');
		expect($schema->properties['category']['options'])->not->toBeEmpty();

		// Check structure
		$firstOption = $schema->properties['category']['options'][0];
		expect($firstOption)->toHaveKey('value');
		expect($firstOption)->toHaveKey('label');
	});
});
