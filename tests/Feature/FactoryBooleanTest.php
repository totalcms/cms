<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();

	$c->get(SchemaSaver::class)->saveSchema([
		'id'         => 'flagtest',
		'name'       => 'Flag Test',
		'type'       => 'object',
		'properties' => [
			'id'        => ['type' => 'string', 'field' => 'id'],
			'title'     => ['type' => 'string', 'field' => 'text', 'factory' => 'sentence'],
			// No explicit factory rule — should be auto-derived as `boolean`.
			'published' => ['type' => 'boolean', 'field' => 'toggle'],
			'featured'  => ['type' => 'boolean', 'field' => 'checkbox', 'default' => true],
			// Explicit rule must win over the boolean auto-derivation.
			'archived'  => ['type' => 'boolean', 'field' => 'toggle', 'factory' => 'boolean(25)'],
			// `boolean(0)` must be honored as 0% true (a lone `0` arg used to be
			// dropped, collapsing this to the default 50%).
			'never'     => ['type' => 'boolean', 'field' => 'toggle', 'factory' => 'boolean(0)'],
			'always'    => ['type' => 'boolean', 'field' => 'toggle', 'factory' => 'boolean(100)'],
		],
	]);

	$col         = new CollectionData();
	$col->id     = 'flags';
	$col->name   = 'Flags';
	$col->schema = 'flagtest';
	$c->get(CollectionSaver::class)->saveCollection($col->toArray());

	$this->factory = $c->get(FactoryImporter::class);
});

it('auto-derives a boolean factory for toggle/checkbox fields without an explicit rule', function (): void {
	$defs = $this->factory->mergeFactoryDefinitions('flags', []);

	expect($defs['published'] ?? null)->toBe('boolean');
	expect($defs['featured'] ?? null)->toBe('boolean');
});

it('lets an explicit factory rule win over the boolean auto-derivation', function (): void {
	$defs = $this->factory->mergeFactoryDefinitions('flags', []);

	expect($defs['archived'] ?? null)->toBe('boolean(25)');
});

it('generates real boolean values for boolean fields', function (): void {
	$defs = $this->factory->mergeFactoryDefinitions('flags', []);

	for ($i = 0; $i < 12; $i++) {
		$object = $this->factory->generateFakeObject('flags', $defs, 'flag-' . $i);

		expect($object['published'])->toBeBool();
		expect($object['featured'])->toBeBool();
		expect($object['archived'])->toBeBool();
	}
});

it('honors a lone 0 argument so boolean(0) is always false and boolean(100) always true', function (): void {
	$defs = $this->factory->mergeFactoryDefinitions('flags', []);

	for ($i = 0; $i < 20; $i++) {
		$object = $this->factory->generateFakeObject('flags', $defs, 'flag-' . $i);

		expect($object['never'])->toBeFalse();
		expect($object['always'])->toBeTrue();
	}
});
