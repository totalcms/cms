<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\XmlRpc\Service\PostMapper;
use TotalCMS\Support\Config;

/**
 * Shared builders for the XML-RPC unit test suite. Required with
 * `require_once` from every test file that needs them (mirroring
 * tests/Feature/XmlRpcTestHelpers.php) rather than being duplicated as
 * top-level functions per file — Pest/PHPUnit loads every *Test.php file
 * into the same process, so a duplicated global function would fatal with
 * "Cannot redeclare".
 */
function blogCollection(string $id, string $schema = 'blog'): CollectionData
{
	$collection         = new CollectionData();
	$collection->id     = $id;
	$collection->name   = ucfirst($id);
	$collection->schema = $schema;

	return $collection;
}

/**
 * @param string $api Base URL used for upload URL absolutize/relativize.
 *                     Defaults to a subpath install (`/tcms`); pass
 *                     `'https://demo.test'` (no path component) to build a
 *                     domain-root mapper instead.
 */
function makePostMapper(string $timezone = 'UTC', string $api = 'https://demo.test/tcms'): PostMapper
{
	$config     = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$reflection = new ReflectionClass($config);

	foreach (['api' => $api, 'timezone' => $timezone] as $property => $value) {
		$prop = $reflection->getProperty($property);
		$prop->setValue($config, $value);
	}

	// `ObjectUrlBuilder` is a `readonly class`, so the anonymous subclass must
	// itself be declared `readonly` — a non-readonly class cannot extend a
	// readonly one (same rule already applied to CollectionLister's double in
	// BlogRegistryTest.php).
	$urlBuilder = new readonly class extends ObjectUrlBuilder {
		public function __construct()
		{
		}

		public function buildUrl(CollectionData $collectionData, array $object): string
		{
			return 'https://demo.test/blog/' . ($object['id'] ?? '');
		}
	};

	return new PostMapper($config, $urlBuilder);
}
