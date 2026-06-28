<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;

/**
 * Unit tests for AdminTwigAdapter::dashboardAutomations().
 *
 * Both AutomationLoader and AutomationRunReader are final readonly — they
 * cannot be mocked with createMock(). We use real instances built without
 * their constructors and set up with pre-canned data via reflection /
 * storage stubs.
 */
final class AdminTwigAdapterDashboardAutomationsTest extends TestCase
{
	// -------------------------------------------------------------------------
	// Stub factories for final classes
	// -------------------------------------------------------------------------

	/**
	 * Build a real AutomationLoader that returns $objects from enabled().
	 * The loader is final readonly — we build it without the constructor and
	 * stub the single method the adapter calls by replacing the private
	 * collaborators it depends on via reflection.
	 *
	 * Instead of a full collaborator chain we use a simpler approach:
	 * newInstanceWithoutConstructor + closure-bind the `enabled` return via
	 * a wrapping anonymous class that shares the same interface shape.
	 *
	 * Since AutomationLoader has no interface, we use a different strategy:
	 * construct the real loader without constructor, then override the private
	 * `collectionFetcher` so that `collectionExists()` returns false (causing
	 * enabled() to return []). For test cases that need objects we'll bypass
	 * the loader entirely and drive the adapter's internal property directly.
	 *
	 * @param list<ObjectData> $objects
	 */
	private function stubLoader(array $objects): AutomationLoader
	{
		// AutomationLoader::enabled() first checks collectionExists('automations').
		// We can't subclass it (final), so we build the full real instance without
		// constructor and inject a CollectionFetcher stub that reports the
		// collection exists, plus an IndexReader stub that returns our object ids,
		// plus an ObjectFetcher stub that returns our ObjectData instances.
		//
		// That is heavier than needed. A cleaner seam: create the loader without
		// constructor and then use Closure::bind to override the `enabled` method
		// … but PHP doesn't support method overrides via closure on concrete classes.
		//
		// Final resolution: use an anonymous class that wraps and re-exposes only
		// what the adapter calls (enabled()). Because AutomationLoader is final we
		// can't extend it, but we can create a subclassable proxy by binding a
		// real instance and forwarding. Since the adapter only calls `enabled()`,
		// we create a tiny wrapper object and stash it in the adapter's property
		// slot — PHP doesn't enforce the declared type at runtime for reflection
		// property sets on non-typed or union-typed properties.
		//
		// Cleanest: create the loader instance without constructor, then use
		// ReflectionClass::newInstanceWithoutConstructor() and set a callable
		// property OR override the relevant storage to produce our objects.
		//
		// We go with the simplest approach: build a loader without its constructor
		// and make its CollectionFetcher report `collectionExists = true`, its
		// IndexReader return an index with just the right ids, and its ObjectFetcher
		// return our pre-built ObjectData objects. This keeps the real enabled()
		// logic running while completely controlling its data.

		// Build a CollectionFetcher stub that says 'automations' exists.
		$colFetcher = (new \ReflectionClass(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class))
			->newInstanceWithoutConstructor();

		// We need collectionExists() to return true for 'automations'.
		// CollectionFetcher is not final — we can mock it.
		$colFetcherMock = $this->createMock(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
		$colFetcherMock->method('collectionExists')->with('automations')->willReturn(count($objects) > 0);

		// Build an IndexReader that returns our ids via fetchIndex.
		$indexReaderMock = $this->createMock(\TotalCMS\Domain\Index\Service\IndexReader::class);

		$rows = array_map(
			static fn (ObjectData $o): array => ['id' => $o->id, 'enabled' => true],
			$objects,
		);

		$indexData = new IndexData($rows);

		$indexReaderMock->method('fetchIndex')->with('automations')->willReturn($indexData);

		// Build an ObjectFetcher that maps ids back to our ObjectData stubs.
		$objectFetcherMock = $this->createMock(\TotalCMS\Domain\Object\Service\ObjectFetcher::class);
		$byId              = [];
		foreach ($objects as $obj) {
			$byId[$obj->id] = $obj;
		}

		$objectFetcherMock->method('fetchObject')
			->willReturnCallback(static function (string $collection, string $id) use ($byId): ObjectData {
				return $byId[$id] ?? throw new \RuntimeException("Unknown id: {$id}");
			});

		$loader = (new \ReflectionClass(AutomationLoader::class))->newInstanceWithoutConstructor();
		$ref    = new \ReflectionClass($loader);
		$ref->getProperty('collectionFetcher')->setValue($loader, $colFetcherMock);
		$ref->getProperty('indexReader')->setValue($loader, $indexReaderMock);
		$ref->getProperty('objectFetcher')->setValue($loader, $objectFetcherMock);
		// registry is not called by enabled() — leave uninitialised (not touched)

		return $loader;
	}

	/**
	 * Build a real AutomationRunReader backed by a hand-rolled storage stub.
	 *
	 * @param array<string,array<string,mixed>> $latest  keyed by automation id
	 */
	private function stubRunReader(array $latest): AutomationRunReader
	{
		$filesystem = new class($latest) implements StorageAdapterInterface {
			/** @param array<string,array<string,mixed>> $latest */
			public function __construct(private readonly array $latest)
			{
			}

			public function directoryExists(string $location): bool
			{
				if ($location === '.system/automations') {
					return $this->latest !== [];
				}

				$parts = explode('/', $location);

				return count($parts) === 4
					&& $parts[0] === '.system'
					&& $parts[1] === 'automations'
					&& $parts[3] === 'runs'
					&& isset($this->latest[$parts[2]]);
			}

			public function listDirectories(string $directory): array
			{
				if ($directory !== '.system/automations') {
					return [];
				}

				return array_map(
					static fn (string $id): string => '.system/automations/' . $id,
					array_keys($this->latest),
				);
			}

			public function listFiles(string $directory): array
			{
				$parts = explode('/', $directory);
				if (count($parts) === 4 && isset($this->latest[$parts[2]])) {
					return [$directory . '/run-001.json'];
				}

				return [];
			}

			public function read(string $location): string
			{
				$parts = explode('/', $location);
				if (count($parts) === 5 && isset($this->latest[$parts[2]])) {
					return (string)json_encode($this->latest[$parts[2]]);
				}

				return '{}';
			}

			public function fileExists(string $location): bool
			{
				return false;
			}

			public function write(string $location, string $contents): bool
			{
				return true;
			}

			public function import(string $import, string $dest): bool
			{
				return true;
			}

			public function move(string $old, string $new): bool
			{
				return true;
			}

			public function copyDirectory(string $old, string $new): bool
			{
				return true;
			}

			public function delete(string $location): bool
			{
				return true;
			}

			public function deleteDirectory(string $location): bool
			{
				return true;
			}

			public function mimeType(string $location): string
			{
				return '';
			}

			public function fileSize(string $location): int
			{
				return 0;
			}

			public function readStream(string $location)
			{
				return fopen('php://memory', 'r');
			}

			public function flysystem(): FilesystemOperator
			{
				throw new \LogicException('not needed in tests');
			}
		};

		return new AutomationRunReader($filesystem);
	}

	// -------------------------------------------------------------------------
	// Adapter factory
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function makeAutomationsAdapter(array $overrides = []): AdminTwigAdapter
	{
		$loader    = $overrides['loader'] ?? $this->stubLoader([]);
		$runReader = $overrides['runReader'] ?? $this->stubRunReader([]);

		$adapter = (new \ReflectionClass(AdminTwigAdapter::class))->newInstanceWithoutConstructor();
		$ref     = new \ReflectionClass($adapter);

		$ref->getProperty('automationLoader')->setValue($adapter, $loader);
		$ref->getProperty('automationRunReader')->setValue($adapter, $runReader);

		return $adapter;
	}

	// -------------------------------------------------------------------------
	// Object helper
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed>|null $triggers
	 * @param array<string,mixed>      $extra
	 */
	private function makeAutomationObject(
		string $id,
		string $name,
		bool $enabled = true,
		?array $triggers = null,
		array $extra = [],
	): ObjectData {
		$props = array_merge(['id' => $id, 'name' => $name, 'enabled' => $enabled], $extra);
		if ($triggers !== null) {
			$props['triggers'] = $triggers;
		}

		return new ObjectData($id, $props);
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — empty
	// -------------------------------------------------------------------------

	public function testReturnsEmptyArrayWhenNoAutomations(): void
	{
		$this->assertSame([], $this->makeAutomationsAdapter()->dashboardAutomations());
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — basic mapping
	// -------------------------------------------------------------------------

	public function testMapsAutomationToExpectedShape(): void
	{
		$obj = $this->makeAutomationObject('send-digest', 'Send Digest', enabled: true);

		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);
		$result  = $adapter->dashboardAutomations();

		$this->assertCount(1, $result);
		$row = $result[0];

		$this->assertSame('send-digest', $row['id']);
		$this->assertSame('Send Digest', $row['name']);
		$this->assertTrue($row['enabled']);
		$this->assertIsString($row['trigger']);
		$this->assertArrayHasKey('lastResult', $row);
		$this->assertArrayHasKey('lastRunAt', $row);
		$this->assertArrayHasKey('nextRunAt', $row);
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — run reader integration
	// -------------------------------------------------------------------------

	public function testLastResultAndLastRunAtPopulatedFromRunReader(): void
	{
		$now = time();
		$obj = $this->makeAutomationObject('cleanup', 'Cleanup');

		$adapter = $this->makeAutomationsAdapter([
			'loader'    => $this->stubLoader([$obj]),
			'runReader' => $this->stubRunReader(['cleanup' => ['status' => 'success', 'runAt' => $now]]),
		]);

		$row = $adapter->dashboardAutomations()[0];
		$this->assertSame('success', $row['lastResult']);
		$this->assertSame($now, $row['lastRunAt']);
	}

	public function testLastResultIsFailedWhenRunFailed(): void
	{
		$now = time();
		$obj = $this->makeAutomationObject('failing-job', 'Failing Job');

		$adapter = $this->makeAutomationsAdapter([
			'loader'    => $this->stubLoader([$obj]),
			'runReader' => $this->stubRunReader(['failing-job' => ['status' => 'failed', 'runAt' => $now]]),
		]);

		$row = $adapter->dashboardAutomations()[0];
		$this->assertSame('failed', $row['lastResult']);
		$this->assertSame($now, $row['lastRunAt']);
	}

	public function testLastResultIsNullWhenNeverRun(): void
	{
		$obj     = $this->makeAutomationObject('new-auto', 'New Automation');
		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);

		$row = $adapter->dashboardAutomations()[0];
		$this->assertNull($row['lastResult']);
		$this->assertNull($row['lastRunAt']);
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — trigger type extraction
	// -------------------------------------------------------------------------

	public function testTriggerTypeExtractedFromFirstTrigger(): void
	{
		$obj = $this->makeAutomationObject(
			'scheduled',
			'Scheduled Job',
			triggers: [['id' => 't1', 'type' => 'schedule', 'cron' => '0 1 * * *']],
		);

		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);
		$this->assertSame('schedule', $adapter->dashboardAutomations()[0]['trigger']);
	}

	public function testTriggerIsWebhookType(): void
	{
		$obj = $this->makeAutomationObject(
			'hook',
			'Webhook Job',
			triggers: [['id' => 't1', 'type' => 'webhook']],
		);

		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);
		$this->assertSame('webhook', $adapter->dashboardAutomations()[0]['trigger']);
	}

	public function testTriggerIsEmptyStringWhenNoTriggers(): void
	{
		$obj     = $this->makeAutomationObject('bare', 'Bare Job');
		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);

		$this->assertSame('', $adapter->dashboardAutomations()[0]['trigger']);
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — nextRunAt is null (deferred)
	// -------------------------------------------------------------------------

	public function testNextRunAtIsNull(): void
	{
		$obj = $this->makeAutomationObject(
			'cron-job',
			'Cron Job',
			triggers: [['id' => 't1', 'type' => 'schedule', 'cron' => '0 1 * * *']],
		);

		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);
		$this->assertNull($adapter->dashboardAutomations()[0]['nextRunAt'], 'nextRunAt is deferred — not yet computed.');
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — multiple automations
	// -------------------------------------------------------------------------

	public function testMultipleAutomationsReturnedCorrectly(): void
	{
		$obj1 = $this->makeAutomationObject('auto-a', 'Auto A');
		$obj2 = $this->makeAutomationObject('auto-b', 'Auto B');
		$now  = time();

		$adapter = $this->makeAutomationsAdapter([
			'loader'    => $this->stubLoader([$obj1, $obj2]),
			'runReader' => $this->stubRunReader([
				'auto-a' => ['status' => 'success', 'runAt' => $now],
				'auto-b' => ['status' => 'failed',  'runAt' => $now - 3600],
			]),
		]);

		$result = $adapter->dashboardAutomations();
		$this->assertCount(2, $result);

		$ids = array_column($result, 'id');
		$this->assertContains('auto-a', $ids);
		$this->assertContains('auto-b', $ids);
	}

	// -------------------------------------------------------------------------
	// dashboardAutomations() — return shape conformance
	// -------------------------------------------------------------------------

	public function testReturnShapeIsCorrect(): void
	{
		$obj = $this->makeAutomationObject(
			'shape-test',
			'Shape Test',
			triggers: [['id' => 't1', 'type' => 'webhook']],
		);

		$adapter = $this->makeAutomationsAdapter(['loader' => $this->stubLoader([$obj])]);

		foreach ($adapter->dashboardAutomations() as $row) {
			$this->assertArrayHasKey('id', $row);
			$this->assertArrayHasKey('name', $row);
			$this->assertArrayHasKey('trigger', $row);
			$this->assertArrayHasKey('enabled', $row);
			$this->assertArrayHasKey('lastResult', $row);
			$this->assertArrayHasKey('lastRunAt', $row);
			$this->assertArrayHasKey('nextRunAt', $row);

			$this->assertIsString($row['id']);
			$this->assertIsString($row['name']);
			$this->assertIsString($row['trigger']);
			$this->assertIsBool($row['enabled']);
			$this->assertTrue(
				$row['lastResult'] === null || in_array($row['lastResult'], ['success', 'failed'], true),
				'lastResult must be null, "success", or "failed".',
			);
			$this->assertTrue($row['lastRunAt'] === null || is_int($row['lastRunAt']));
			$this->assertNull($row['nextRunAt']);
		}
	}
}
