<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

function makeTestExtensionContext(string $extensionPath = '/path/to/extension'): ExtensionContext
{
	$manifest = ExtensionManifest::fromArray([
		'id'      => 'test-vendor/search-ext',
		'name'    => 'Search Extension',
		'version' => '1.0.0',
	]);

	$container = test()->createMock(Psr\Container\ContainerInterface::class);
	$storage   = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);
	$settings = new ExtensionSettingsManager($storage);

	return new ExtensionContext($manifest, $extensionPath, $container, $settings, new NullLogger());
}

/**
 * Minimal anonymous SearchProvider for test use.
 */
function makeSearchProvider(string $id): SearchProvider
{
	return new class($id) implements SearchProvider {
		public function __construct(private readonly string $id)
		{
		}

		public function id(): string
		{
			return $this->id;
		}

		public function label(): string
		{
			return ucfirst($this->id);
		}

		public function search(SearchQuery $query): array
		{
			return [];
		}

		public function index(string $collection, string $id, array $data): void
		{
		}

		public function delete(string $collection, string $id): void
		{
		}

		public function isAvailable(): bool
		{
			return true;
		}
	};
}

describe('ExtensionContext::registerSearchProvider', function (): void {
	test('defaults to empty search providers list', function (): void {
		$ctx = makeTestExtensionContext();

		expect($ctx->getRegisteredSearchProviders())->toBe([]);
	});

	test('registerSearchProvider stores the provider', function (): void {
		$ctx      = makeTestExtensionContext();
		$provider = makeSearchProvider('algolia');

		$ctx->registerSearchProvider($provider);

		expect($ctx->getRegisteredSearchProviders())->toHaveCount(1);
		expect($ctx->getRegisteredSearchProviders()[0]->id())->toBe('algolia');
	});

	test('multiple providers are all stored in registration order', function (): void {
		$ctx = makeTestExtensionContext();

		$ctx->registerSearchProvider(makeSearchProvider('algolia'));
		$ctx->registerSearchProvider(makeSearchProvider('meilisearch'));

		$providers = $ctx->getRegisteredSearchProviders();
		expect($providers)->toHaveCount(2);
		expect($providers[0]->id())->toBe('algolia');
		expect($providers[1]->id())->toBe('meilisearch');
	});

	test('getCapabilities includes mcp:search when providers are registered', function (): void {
		$ctx = makeTestExtensionContext();
		$ctx->registerSearchProvider(makeSearchProvider('algolia'));

		$caps = $ctx->getCapabilities();

		expect($caps)->toHaveKey('mcp:search');
		expect($caps['mcp:search'])->toBeTrue();
	});

	test('getCapabilities does not include mcp:search when no providers registered', function (): void {
		$ctx = makeTestExtensionContext();

		expect($ctx->getCapabilities())->not->toHaveKey('mcp:search');
	});

	test('capabilityLabels includes mcp:search with label Search Provider', function (): void {
		$labels = ExtensionContext::capabilityLabels();

		expect($labels)->toHaveKey('mcp:search');
		expect($labels['mcp:search'])->toBe('Search Provider');
	});
});
