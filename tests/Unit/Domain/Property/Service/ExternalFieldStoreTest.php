<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CodeData;
use TotalCMS\Domain\Property\Service\ExternalFieldStore;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

final class ExternalFieldStoreTest extends TestCase
{
	private function makeStore(): ExternalFieldStore
	{
		// SchemaFetcher + filesystem are only used by persist()/hydrate().
		return new ExternalFieldStore(
			$this->createMock(StorageAdapterInterface::class),
			$this->createMock(SchemaFetcher::class),
			new DangerousCodeScanner(),
		);
	}

	/**
	 * Build a store whose SchemaFetcher returns a schema with one external
	 * `handler` field, backed by an in-memory fake filesystem.
	 *
	 * @param array<string,string> $files passed by reference to observe writes
	 */
	private function storeWithSchema(array &$files): ExternalFieldStore
	{
		$schema             = new SchemaData();
		$schema->properties = [
			'id'      => ['type' => 'string', 'field' => 'text'],
			'handler' => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'php', 'external' => true]],
		];

		$fetcher = $this->createMock(SchemaFetcher::class);
		$fetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fs = $this->createMock(StorageAdapterInterface::class);
		$fs->method('write')->willReturnCallback(function (string $path, string $contents) use (&$files): bool {
			$files[$path] = $contents;

			return true;
		});
		$fs->method('fileExists')->willReturnCallback(fn (string $path): bool => isset($files[$path]));
		$fs->method('read')->willReturnCallback(fn (string $path): string => $files[$path] ?? '');

		return new ExternalFieldStore($fs, $fetcher, new DangerousCodeScanner());
	}

	public function testExternalFieldsMapsExternalCodeFieldsToTheirExtension(): void
	{
		$properties = [
			'title'   => ['type' => 'string', 'field' => 'text'],
			'handler' => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'php', 'external' => true]],
			'body'    => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'twig', 'external' => true]],
			'notes'   => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'twig']], // not external
		];

		expect($this->makeStore()->externalFields($properties))->toBe([
			'handler' => 'php',
			'body'    => 'twig',
		]);
	}

	public function testExternalFieldsDefaultsUnknownModesToTxtAndIgnoresNonCodeFields(): void
	{
		$properties = [
			'a' => ['field' => 'code', 'settings' => ['external' => true]],                  // no mode
			'b' => ['field' => 'code', 'settings' => ['mode' => 'ruby', 'external' => true]], // unknown mode
			'c' => ['field' => 'image', 'settings' => ['external' => true]],                  // not code
		];

		expect($this->makeStore()->externalFields($properties))->toBe([
			'a' => 'txt',
			'b' => 'txt',
		]);
	}

	public function testSidecarPathFollowsCollectionIdPropertyConvention(): void
	{
		expect($this->makeStore()->sidecarPath('automations', 'process-monthly', 'handler', 'php'))
			->toBe('automations/process-monthly/handler/handler.php');
	}

	public function testPersistWritesSidecarFilesAndReportsFieldNames(): void
	{
		$files = [];
		$store = $this->storeWithSchema($files);

		$object = new ObjectData('process-monthly', [
			'handler' => new CodeData('<?php return fn($ctx) => 1;', ['mode' => 'php', 'external' => true]),
		]);

		$blanked = $store->persist('automations', $object);

		expect($blanked)->toBe(['handler']);
		expect($files['automations/process-monthly/handler/handler.php'])
			->toBe('<?php return fn($ctx) => 1;');
	}

	public function testPersistRejectsPhpHandlerWithBlockingPatterns(): void
	{
		$files = [];
		$store = $this->storeWithSchema($files);

		$object = new ObjectData('evil', [
			'handler' => new CodeData('<?php exec("rm -rf /");', ['mode' => 'php', 'external' => true]),
		]);

		try {
			$store->persist('automations', $object);
			$this->fail('expected the dangerous handler to be rejected');
		} catch (\DomainException $e) {
			expect($e->getMessage())->toContain('exec');
		}

		// Aborts before the sidecar is written (persist runs before the JSON write).
		expect($files)->toBe([]);
	}

	public function testPersistAllowsDualUseFileAndNetworkCalls(): void
	{
		$files = [];
		$store = $this->storeWithSchema($files);

		// file_put_contents is advisory-only, not in BLOCKING_PATTERNS, so it saves.
		$object = new ObjectData('writer', [
			'handler' => new CodeData('<?php file_put_contents("/tmp/x", "y");', ['mode' => 'php', 'external' => true]),
		]);

		$blanked = $store->persist('automations', $object);

		expect($blanked)->toBe(['handler']);
		expect($files['automations/writer/handler/handler.php'])->toBe('<?php file_put_contents("/tmp/x", "y");');
	}

	public function testHydrateReadsSidecarFilesIntoTheObject(): void
	{
		$files = ['automations/process-monthly/handler/handler.php' => '<?php return 42;'];
		$store = $this->storeWithSchema($files);

		// Object loaded from blanked JSON: handler is empty until hydrated.
		$object = new ObjectData('process-monthly', [
			'handler' => new CodeData('', ['mode' => 'php', 'external' => true]),
		]);

		$store->hydrate('automations', $object);

		expect((string)$object->properties->get('handler'))->toBe('<?php return 42;');
	}
}
