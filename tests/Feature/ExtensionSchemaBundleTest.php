<?php

/**
 * An extension's schema with a custom copy of the same id made the bundle
 * check report "installation has been corrupted" and refuse every write.
 *
 * Core and custom schemas shared the cache key `schema:{id}`. Once the custom
 * copy was cached, the core lookup returned it for the extension's id, the
 * list of core schemas took it in, and BundleChecker looked for it in the
 * manifest. Two installs sharing one data folder and one cache (only one of
 * them with the extension) hit it in production: the copy was made on purpose,
 * for the install without the extension.
 */

use TotalCMS\Domain\Bundle\Data\BundleData;
use TotalCMS\Domain\Bundle\Repository\BundleRepository;
use TotalCMS\Domain\Bundle\Service\BundleChecker;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();

	// Two installs on one data folder and one cache; only the hub has the extension.
	$this->hub     = bootstrap()->getContainer();
	$this->country = bootstrap()->getContainer();
	$this->hub->get(CacheManager::class)->clearAllComputedData();

	$schema = json_decode((string)file_get_contents(SchemaRepository::defaultSchemaDir() . 'text.json'), true);

	$this->extensionDir = sys_get_temp_dir() . '/tcms-ext-schemas-' . uniqid();
	mkdir($this->extensionDir);
	file_put_contents("{$this->extensionDir}/deepl-translation.json", json_encode(['id' => 'deepl-translation', 'description' => 'EXTENSION'] + $schema));
	$this->hub->get(SchemaRepository::class)->registerExtensionSchemaDir($this->extensionDir);

	// The custom copy, written as a file the way a deploy script would.
	$this->copy = function () use ($schema): void {
		if (!is_dir(cmsDataDir() . '/.schemas')) {
			mkdir(cmsDataDir() . '/.schemas');
		}
		file_put_contents(cmsDataDir() . '/.schemas/deepl-translation.json', json_encode(['id' => 'deepl-translation', 'description' => 'COPY'] + $schema));
	};

	// The manifest's schema entries, hashed from disk, so the check does not
	// depend on resources/bundle being rebuilt while files are being edited.
	// Built by hand: the container resolved BundleChecker while it booted.
	$this->checker = function ($container, array $leaveOut = []): BundleChecker {
		$entries = [];
		foreach (SchemaData::RESERVED_SCHEMAS as $id) {
			if (!in_array($id, $leaveOut, true)) {
				$entries["resources/schemas/{$id}.json"] = (string)hash_file('sha256', SchemaRepository::defaultSchemaDir() . "{$id}.json");
			}
		}
		$bundles = $this->createStub(BundleRepository::class);
		$bundles->method('fetchBundle')->willReturn(BundleData::fromArray($entries));
		$bundles->method('saveLocalBundle')->willReturn(true);
		$bundles->method('bundleExists')->willReturn(true);
		$bundles->method('localBundleExists')->willReturn(true);
		// Warm the install's schema caches the way a request would.
		$container->get(SchemaLister::class)->listAllSchemas();

		return new BundleChecker($bundles);
	};
});

afterEach(function (): void {
	recursiveDelete($this->extensionDir);
	$this->hub->get(CacheManager::class)->clearAllComputedData();
});

it('passes the bundle check when a custom copy was read before the core list was built', function (): void {
	($this->copy)();
	$this->hub->get(SchemaRepository::class)->listCustomSchemas();

	expect(($this->checker)($this->hub)->verify())->toBeTrue();
});

it('passes the bundle check when the custom copy is gone but still cached', function (): void {
	($this->copy)();
	$this->hub->get(SchemaRepository::class)->listCustomSchemas();
	unlink(cmsDataDir() . '/.schemas/deepl-translation.json');

	expect(($this->checker)($this->hub)->verify())->toBeTrue();
});

it('passes the bundle check on an install without the extension that shares the cache', function (): void {
	($this->copy)();
	$this->country->get(SchemaRepository::class)->getSchema('deepl-translation');
	$this->hub->get(SchemaLister::class)->listReservedSchemas();

	expect(($this->checker)($this->country)->verify())->toBeTrue()
		->and(($this->checker)($this->hub)->verify())->toBeTrue();
});

it('ignores a core schema list cached before the update', function (): void {
	$this->hub->get(CacheManager::class)->storeComputedData('reserved_schemas_list', [
		['id' => 'deepl-translation', 'type' => 'object', 'properties' => []],
	]);

	expect(($this->checker)($this->hub)->verify())->toBeTrue();
});

it('still fails the check when a core schema is missing from the manifest', function (): void {
	expect(fn () => ($this->checker)($this->hub, ['text'])->verify())
		->toThrow(DomainException::class, 'resources/schemas/text.json missing from Bundle.');
});

it('still fails the check when a core schema file has changed', function (): void {
	$bundles = $this->createStub(BundleRepository::class);
	$bundles->method('fetchBundle')->willReturn(BundleData::fromArray(['resources/schemas/text.json' => str_repeat('0', 64)]));

	expect(fn () => (new BundleChecker($bundles))->verify())
		->toThrow(UnexpectedValueException::class, 'resources/schemas/text.json cannot be verified.');
});

it('lists only the schemas Total CMS ships as core schemas', function (): void {
	($this->copy)();
	$this->hub->get(SchemaRepository::class)->listCustomSchemas();

	$ids = array_map(static fn (SchemaData $schema): string => $schema->id, $this->hub->get(SchemaLister::class)->listReservedSchemas());

	expect($ids)->not->toContain('deepl-translation')
		->and(array_values(array_diff(SchemaData::RESERVED_SCHEMAS, $ids)))->toBe([]);
});

it('keeps the extension schema ahead of a cached custom copy', function (): void {
	($this->copy)();
	$this->country->get(SchemaRepository::class)->getSchema('deepl-translation');
	$this->hub->get(SchemaRepository::class)->listCustomSchemas();

	expect($this->hub->get(SchemaRepository::class)->getSchema('deepl-translation')->description)->toBe('EXTENSION')
		->and($this->country->get(SchemaRepository::class)->getSchema('deepl-translation')->description)->toBe('COPY');
});

it('still refuses to save a custom schema over an extension schema', function (): void {
	expect(fn () => $this->hub->get(SchemaSaver::class)->saveSchema([
		'id' => 'deepl-translation', 'type' => 'object', 'properties' => [],
	]))->toThrow(UnexpectedValueException::class, 'is reserved');
});
