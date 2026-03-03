<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

describe('PropertyMetaResolver', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher     = $this->createMock(SchemaFetcher::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->config            = Config::init();

		$this->schemaData             = new SchemaData();
		$this->schemaData->id         = 'test-schema';
		$this->schemaData->required   = [];
		$this->schemaData->properties = [];

		$this->collectionData             = new CollectionData();
		$this->collectionData->id         = 'test-collection';
		$this->collectionData->schema     = 'test-schema';
		$this->collectionData->properties = [];

		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->schemaData);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionData);
	});

	function createResolver(object $ctx): PropertyMetaResolver
	{
		return new PropertyMetaResolver($ctx->schemaFetcher, $ctx->collectionFetcher, $ctx->config);
	}

	// ==================== resolve() - basic merging ====================

	test('returns schema-level field metadata', function (): void {
		$this->schemaData->properties = [
			'title' => ['field' => 'text', 'label' => 'Title', 'help' => 'Enter a title'],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolve('test-collection', 'title');

		expect($result['field'])->toBe('text');
		expect($result['label'])->toBe('Title');
		expect($result['help'])->toBe('Enter a title');
	});

	test('collection properties override schema properties', function (): void {
		$this->schemaData->properties = [
			'title' => ['field' => 'text', 'label' => 'Title', 'help' => 'Schema help'],
		];
		$this->collectionData->properties = [
			'title' => ['label' => 'Custom Title'],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolve('test-collection', 'title');

		expect($result['label'])->toBe('Custom Title');
		expect($result['help'])->toBe('Schema help');
		expect($result['field'])->toBe('text');
	});

	test('custom properties override collection and schema properties', function (): void {
		$this->schemaData->properties = [
			'title' => ['field' => 'text', 'label' => 'Schema Label', 'help' => 'Schema help'],
		];
		$this->collectionData->properties = [
			'title' => ['label' => 'Collection Label'],
		];
		$this->collectionData->customProperties = [
			'obj-1' => [
				'title' => ['label' => 'Object Label'],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolve('test-collection', 'title', 'obj-1');

		expect($result['label'])->toBe('Object Label');
		expect($result['help'])->toBe('Schema help');
	});

	test('custom properties are not applied without objectId', function (): void {
		$this->schemaData->properties = [
			'title' => ['field' => 'text', 'label' => 'Schema Label'],
		];
		$this->collectionData->customProperties = [
			'obj-1' => [
				'title' => ['label' => 'Object Label'],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolve('test-collection', 'title');

		expect($result['label'])->toBe('Schema Label');
	});

	test('passes through all schema keys for full property meta inheritance', function (): void {
		$this->schemaData->properties = [
			'title' => [
				'field'       => 'text',
				'label'       => 'Title',
				'help'        => 'Help text',
				'placeholder' => 'Enter...',
				'options'     => ['a', 'b'],
				'settings'    => ['maxlength' => 100],
				'type'        => 'string',
				'$ref'        => 'https://example.com',
				'required'    => true,
				'deckref'     => 'some-ref',
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolve('test-collection', 'title');

		expect($result)->toHaveKeys(['field', 'label', 'help', 'placeholder', 'options', 'settings']);
		expect($result)->toHaveKey('type');
		expect($result)->toHaveKey('$ref');
		expect($result)->toHaveKey('required');
		expect($result)->toHaveKey('deckref');
	});

	test('returns empty array for missing property', function (): void {
		$this->schemaData->properties = [];

		$resolver = createResolver($this);
		$result   = $resolver->resolve('test-collection', 'nonexistent');

		expect($result)->toBe(['settings' => []]);
	});

	test('handles null collection data gracefully', function (): void {
		$this->schemaData->properties = [
			'title' => ['field' => 'text', 'label' => 'Title'],
		];
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$resolver = new PropertyMetaResolver($this->schemaFetcher, $this->collectionFetcher, $this->config);
		$result   = $resolver->resolve('test-collection', 'title');

		expect($result['label'])->toBe('Title');
		expect($result['field'])->toBe('text');
	});

	// ==================== resolveSettings() ====================

	test('resolveSettings returns only the settings array', function (): void {
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'label' => 'Content', 'settings' => ['height' => 400]],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content');

		expect($result)->toBe(['height' => 400]);
	});

	test('resolveSettings returns empty array when no settings', function (): void {
		$this->schemaData->properties = [
			'title' => ['field' => 'text', 'label' => 'Title'],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'title');

		expect($result)->toBe([]);
	});

	// ==================== Settings merge order ====================

	test('settings merge: collection overrides schema', function (): void {
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['height' => 400, 'toolbar' => 'basic']],
		];
		$this->collectionData->properties = [
			'content' => ['settings' => ['height' => 600]],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content');

		expect($result['height'])->toBe(600);
		expect($result['toolbar'])->toBe('basic');
	});

	test('settings merge: custom overrides collection and schema', function (): void {
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['height' => 400, 'toolbar' => 'basic']],
		];
		$this->collectionData->properties = [
			'content' => ['settings' => ['height' => 600]],
		];
		$this->collectionData->customProperties = [
			'obj-1' => [
				'content' => ['settings' => ['height' => 800, 'theme' => 'dark']],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content', 'obj-1');

		expect($result['height'])->toBe(800);
		expect($result['toolbar'])->toBe('basic');
		expect($result['theme'])->toBe('dark');
	});

	// ==================== Named preset resolution ====================

	test('resolvePreset loads named preset as base', function (): void {
		$this->config->presets = [
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['height' => 400, 'toolbar' => 'basic'],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolvePreset(['preset' => 'blog-editor']);

		expect($result['height'])->toBe(400);
		expect($result['toolbar'])->toBe('basic');
		expect($result)->not->toHaveKey('preset');
	});

	test('explicit settings override preset values', function (): void {
		$this->config->presets = [
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['height' => 400, 'toolbar' => 'basic'],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolvePreset(['preset' => 'blog-editor', 'height' => 600]);

		expect($result['height'])->toBe(600);
		expect($result['toolbar'])->toBe('basic');
	});

	test('returns settings unchanged when preset not found', function (): void {
		$this->config->presets = [];

		$resolver = createResolver($this);
		$result   = $resolver->resolvePreset(['preset' => 'nonexistent', 'height' => 300]);

		expect($result['height'])->toBe(300);
		expect($result)->not->toHaveKey('preset');
	});

	test('returns settings unchanged when no preset key', function (): void {
		$resolver = createResolver($this);
		$result   = $resolver->resolvePreset(['height' => 300]);

		expect($result)->toBe(['height' => 300]);
	});

	test('resolves preset from flat format', function (): void {
		$this->config->presets = [
			'simple-preset' => ['height' => 500, 'toolbar' => 'full'],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolvePreset(['preset' => 'simple-preset']);

		expect($result['height'])->toBe(500);
		expect($result['toolbar'])->toBe('full');
	});

	// ==================== Type-default preset resolution ====================

	test('resolveTypePreset loads preset matching field type', function (): void {
		$this->config->presets = [
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveTypePreset('styledtext');

		expect($result['toolbar'])->toBe('minimal');
		expect($result['height'])->toBe(300);
	});

	test('resolveTypePreset returns empty array when no matching preset', function (): void {
		$this->config->presets = [];

		$resolver = createResolver($this);
		$result   = $resolver->resolveTypePreset('styledtext');

		expect($result)->toBe([]);
	});

	test('resolveTypePreset handles flat format', function (): void {
		$this->config->presets = [
			'image' => ['maxWidth' => 1200, 'quality' => 85],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveTypePreset('image');

		expect($result['maxWidth'])->toBe(1200);
		expect($result['quality'])->toBe(85);
	});

	test('resolveTypePreset returns empty for preset with empty settings', function (): void {
		$this->config->presets = [
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => [],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveTypePreset('styledtext');

		expect($result)->toBe([]);
	});

	// ==================== Type-default fallback in resolve ====================

	test('type-default preset applies when field has no settings at any level', function (): void {
		$this->config->presets = [
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
		];
		$this->schemaData->properties = [
			'summary' => ['field' => 'styledtext'],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'summary');

		expect($result['toolbar'])->toBe('minimal');
		expect($result['height'])->toBe(300);
	});

	test('type-default preset does not apply when field has explicit settings', function (): void {
		$this->config->presets = [
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
		];
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['height' => 500]],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content');

		expect($result['height'])->toBe(500);
		expect($result)->not->toHaveKey('toolbar');
	});

	test('named preset takes priority over type-default', function (): void {
		$this->config->presets = [
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['toolbar' => 'full', 'height' => 600],
			],
		];
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['preset' => 'blog-editor']],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content');

		expect($result['toolbar'])->toBe('full');
		expect($result['height'])->toBe(600);
	});

	// ==================== Preset resolution at each settings layer ====================

	test('preset in schema settings is resolved', function (): void {
		$this->config->presets = [
			'my-preset' => ['height' => 400, 'toolbar' => 'full'],
		];
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['preset' => 'my-preset']],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content');

		expect($result['height'])->toBe(400);
		expect($result['toolbar'])->toBe('full');
	});

	test('preset in collection settings is resolved', function (): void {
		$this->config->presets = [
			'my-preset' => ['height' => 500],
		];
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['toolbar' => 'basic']],
		];
		$this->collectionData->properties = [
			'content' => ['settings' => ['preset' => 'my-preset']],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content');

		expect($result['toolbar'])->toBe('basic');
		expect($result['height'])->toBe(500);
	});

	test('preset in custom settings is resolved', function (): void {
		$this->config->presets = [
			'special' => ['theme' => 'dark'],
		];
		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['height' => 400]],
		];
		$this->collectionData->customProperties = [
			'obj-1' => [
				'content' => ['settings' => ['preset' => 'special']],
			],
		];

		$resolver = createResolver($this);
		$result   = $resolver->resolveSettings('test-collection', 'content', 'obj-1');

		expect($result['height'])->toBe(400);
		expect($result['theme'])->toBe('dark');
	});
});
