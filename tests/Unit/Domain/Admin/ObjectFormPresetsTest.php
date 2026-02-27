<?php

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\ObjectForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\Config;

/**
 * Test ObjectForm preset resolution: named presets and type-default presets.
 */
describe('ObjectForm Preset Resolution', function (): void {
	beforeEach(function (): void {
		$this->objectFetcher            = $this->createMock(ObjectFetcher::class);
		$this->collectionFetcher        = $this->createMock(CollectionFetcher::class);
		$this->collectionLister         = $this->createMock(CollectionLister::class);
		$this->indexReader              = $this->createMock(IndexReader::class);
		$this->indexFilter              = $this->createMock(IndexFilter::class);
		$this->schemaFetcher            = $this->createMock(SchemaFetcher::class);
		$this->schemaLister             = $this->createMock(SchemaLister::class);
		$this->accessGroupLister        = $this->createMock(AccessGroupLister::class);
		$this->collectionEditionService = $this->createMock(CollectionEditionService::class);
		$this->editionFeatures          = $this->createMock(EditionFeatureService::class);

		$this->schemaData             = new SchemaData();
		$this->schemaData->id         = 'test-schema';
		$this->schemaData->required   = [];

		$this->collectionData             = new CollectionData();
		$this->collectionData->id         = 'test-collection';
		$this->collectionData->schema     = 'test-schema';

		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaData);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionData);
		$this->objectFetcher->method('existsObject')->willReturn(false);
	});

	/**
	 * Helper to create a Config mock with specific presets.
	 *
	 * @param array<string,mixed> $presets
	 */
	function createConfigWithPresets(array $presets): Config
	{
		$config = Config::init();
		$config->presets = $presets;

		return $config;
	}

	/**
	 * Helper to create an ObjectForm and call resolvePreset via reflection.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	function callResolvePreset(object $testContext, array $settings, Config $config): array
	{
		$form = new ObjectForm(
			objectFetcher: $testContext->objectFetcher,
			collectionFetcher: $testContext->collectionFetcher,
			collectionLister: $testContext->collectionLister,
			collectionReader: $testContext->indexReader,
			indexFilter: $testContext->indexFilter,
			schemaFetcher: $testContext->schemaFetcher,
			schemaLister: $testContext->schemaLister,
			accessGroupLister: $testContext->accessGroupLister,
			collectionEditionService: $testContext->collectionEditionService,
			editionFeatures: $testContext->editionFeatures,
			api: '/api',
			collection: 'test-collection',
			config: $config,
		);

		$reflection = new ReflectionClass($form);
		$method = $reflection->getMethod('resolvePreset');

		return $method->invoke($form, $settings);
	}

	/**
	 * Helper to create an ObjectForm and call resolveTypePreset via reflection.
	 *
	 * @return array<string,mixed>
	 */
	function callResolveTypePreset(object $testContext, string $fieldType, Config $config): array
	{
		$form = new ObjectForm(
			objectFetcher: $testContext->objectFetcher,
			collectionFetcher: $testContext->collectionFetcher,
			collectionLister: $testContext->collectionLister,
			collectionReader: $testContext->indexReader,
			indexFilter: $testContext->indexFilter,
			schemaFetcher: $testContext->schemaFetcher,
			schemaLister: $testContext->schemaLister,
			accessGroupLister: $testContext->accessGroupLister,
			collectionEditionService: $testContext->collectionEditionService,
			editionFeatures: $testContext->editionFeatures,
			api: '/api',
			collection: 'test-collection',
			config: $config,
		);

		$reflection = new ReflectionClass($form);
		$method = $reflection->getMethod('resolveTypePreset');

		return $method->invoke($form, $fieldType);
	}

	/**
	 * Helper to create an ObjectForm and call resolveFieldSettings via reflection.
	 *
	 * @return array<string,mixed>
	 */
	function callResolveFieldSettings(object $testContext, string $property, string $fieldType, Config $config): array
	{
		$form = new ObjectForm(
			objectFetcher: $testContext->objectFetcher,
			collectionFetcher: $testContext->collectionFetcher,
			collectionLister: $testContext->collectionLister,
			collectionReader: $testContext->indexReader,
			indexFilter: $testContext->indexFilter,
			schemaFetcher: $testContext->schemaFetcher,
			schemaLister: $testContext->schemaLister,
			accessGroupLister: $testContext->accessGroupLister,
			collectionEditionService: $testContext->collectionEditionService,
			editionFeatures: $testContext->editionFeatures,
			api: '/api',
			collection: 'test-collection',
			config: $config,
		);

		$reflection = new ReflectionClass($form);
		$method = $reflection->getMethod('resolveFieldSettings');

		return $method->invoke($form, $property, $fieldType);
	}

	// ==================== Named Preset Resolution ====================

	test('resolves named preset from deck format', function (): void {
		$config = createConfigWithPresets([
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['height' => 400, 'toolbar' => 'basic'],
			],
		]);

		$settings = ['preset' => 'blog-editor'];
		$result = callResolvePreset($this, $settings, $config);

		expect($result)->toHaveKey('height');
		expect($result['height'])->toBe(400);
		expect($result['toolbar'])->toBe('basic');
		expect($result)->not->toHaveKey('preset');
	});

	test('explicit settings override preset values', function (): void {
		$config = createConfigWithPresets([
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['height' => 400, 'toolbar' => 'basic'],
			],
		]);

		$settings = ['preset' => 'blog-editor', 'height' => 600];
		$result = callResolvePreset($this, $settings, $config);

		// Explicit height should override preset
		expect($result['height'])->toBe(600);
		// Preset toolbar should still apply
		expect($result['toolbar'])->toBe('basic');
	});

	test('returns settings unchanged when preset not found', function (): void {
		$config = createConfigWithPresets([]);

		$settings = ['preset' => 'nonexistent', 'height' => 300];
		$result = callResolvePreset($this, $settings, $config);

		expect($result['height'])->toBe(300);
		expect($result)->not->toHaveKey('preset');
	});

	test('returns settings unchanged when no preset key', function (): void {
		$config = createConfigWithPresets([
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['height' => 400],
			],
		]);

		$settings = ['height' => 300];
		$result = callResolvePreset($this, $settings, $config);

		expect($result['height'])->toBe(300);
	});

	test('resolves preset from flat format (non-deck)', function (): void {
		$config = createConfigWithPresets([
			'simple-preset' => ['height' => 500, 'toolbar' => 'full'],
		]);

		$settings = ['preset' => 'simple-preset'];
		$result = callResolvePreset($this, $settings, $config);

		expect($result['height'])->toBe(500);
		expect($result['toolbar'])->toBe('full');
	});

	// ==================== Type-Default Preset Resolution ====================

	test('resolves type-default preset from deck format', function (): void {
		$config = createConfigWithPresets([
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
		]);

		$result = callResolveTypePreset($this, 'styledtext', $config);

		expect($result)->toHaveKey('toolbar');
		expect($result['toolbar'])->toBe('minimal');
		expect($result['height'])->toBe(300);
	});

	test('returns empty array when no type-default preset exists', function (): void {
		$config = createConfigWithPresets([]);

		$result = callResolveTypePreset($this, 'styledtext', $config);

		expect($result)->toBe([]);
	});

	test('resolves type-default preset from flat format', function (): void {
		$config = createConfigWithPresets([
			'image' => ['maxWidth' => 1200, 'quality' => 85],
		]);

		$result = callResolveTypePreset($this, 'image', $config);

		expect($result['maxWidth'])->toBe(1200);
		expect($result['quality'])->toBe(85);
	});

	test('type-default preset with empty settings returns empty', function (): void {
		$config = createConfigWithPresets([
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => [],
			],
		]);

		$result = callResolveTypePreset($this, 'styledtext', $config);

		expect($result)->toBe([]);
	});

	// ==================== Full Field Settings Resolution ====================

	test('type-default preset only applies when field has no settings', function (): void {
		$config = createConfigWithPresets([
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
		]);

		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['height' => 500]],
			'summary' => ['field' => 'styledtext'],
		];

		// "content" has explicit settings, type-default should NOT apply
		$contentSettings = callResolveFieldSettings($this, 'content', 'styledtext', $config);
		expect($contentSettings['height'])->toBe(500);
		expect($contentSettings)->not->toHaveKey('toolbar');

		// "summary" has no settings, type-default SHOULD apply
		$summarySettings = callResolveFieldSettings($this, 'summary', 'styledtext', $config);
		expect($summarySettings['toolbar'])->toBe('minimal');
		expect($summarySettings['height'])->toBe(300);
	});

	test('explicit preset takes priority over type-default', function (): void {
		$config = createConfigWithPresets([
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => ['toolbar' => 'minimal', 'height' => 300],
			],
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => ['toolbar' => 'full', 'height' => 600],
			],
		]);

		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['preset' => 'blog-editor']],
		];

		// Has explicit preset "blog-editor", should use that, not type-default "styledtext"
		$settings = callResolveFieldSettings($this, 'content', 'styledtext', $config);
		expect($settings['toolbar'])->toBe('full');
		expect($settings['height'])->toBe(600);
	});

	test('collection settings override schema settings', function (): void {
		$config = createConfigWithPresets([]);

		$this->schemaData->properties = [
			'content' => ['field' => 'styledtext', 'settings' => ['height' => 400, 'toolbar' => 'basic']],
		];
		$this->collectionData->properties = [
			'content' => ['settings' => ['height' => 600]],
		];

		$settings = callResolveFieldSettings($this, 'content', 'styledtext', $config);
		expect($settings['height'])->toBe(600);
		expect($settings['toolbar'])->toBe('basic');
	});

	test('field with no settings and no type preset returns empty', function (): void {
		$config = createConfigWithPresets([]);

		$this->schemaData->properties = [
			'title' => ['field' => 'text'],
		];

		$settings = callResolveFieldSettings($this, 'title', 'text', $config);
		expect($settings)->toBe([]);
	});
});
