<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Listener;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Listener\ContentChangeListener;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class ContentChangeListenerTest extends TestCase
{
	public function testRoutesObjectCreatedToProviderIndex(): void
	{
		$called   = null;
		$provider = $this->makeProvider(
			'algolia',
			indexCallback: function (string $c, string $id, array $data) use (&$called): void {
				$called = ['c' => $c, 'id' => $id, 'data' => $data];
			},
		);

		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$listener = new ContentChangeListener(
			$registry,
			$this->createMock(JobQueuer::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia', 'indexOnSave' => true]),
		);

		$listener->onObjectSaved((new ObjectEventPayload('blog', 'post-1', new ObjectData('post-1', [])))->toArray());

		$this->assertSame('blog', $called['c'] ?? null);
		$this->assertSame('post-1', $called['id'] ?? null);
		// The payload carries a live ObjectData; the provider must get its array form.
		$this->assertSame(['id' => 'post-1'], $called['data'] ?? null);
	}

	public function testSkipsWhenActiveIsText(): void
	{
		$provider = $this->makeProvider('text', indexCallback: fn () => $this->fail('Should not call index for text provider'));

		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$listener = new ContentChangeListener(
			$registry,
			$this->createMock(JobQueuer::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => Level::Debug]),
			$this->makeConfig(['activeProvider' => 'text', 'indexOnSave' => true]),
		);

		$listener->onObjectSaved((new ObjectEventPayload('blog', 'post-1', new ObjectData('post-1', [])))->toArray());

		$this->assertTrue(true);
	}

	public function testSkipsWhenIndexOnSaveIsDisabled(): void
	{
		$provider = $this->makeProvider('algolia', indexCallback: fn () => $this->fail('indexOnSave is false; should not call index'));

		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$listener = new ContentChangeListener(
			$registry,
			$this->createMock(JobQueuer::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia', 'indexOnSave' => false]),
		);

		$listener->onObjectSaved((new ObjectEventPayload('blog', 'post-1', new ObjectData('post-1', [])))->toArray());

		$this->assertTrue(true);
	}

	public function testEnqueuesRetryWhenProviderIndexThrows(): void
	{
		$provider = $this->makeProvider(
			'algolia',
			indexCallback: fn () => throw new \RuntimeException('Algolia is down'),
		);

		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$jobs = $this->createMock(JobQueuer::class);
		$jobs->expects($this->once())
			->method('queueJob')
			->with('search.reindex', 'blog', $this->callback(
				static fn (array $payload): bool => $payload['object_id'] === 'post-1' && ($payload['operation'] ?? '') === 'index'
			));

		$listener = new ContentChangeListener(
			$registry,
			$jobs,
			new LoggerFactory(['test' => new NullLogger(), 'level' => Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia', 'indexOnSave' => true]),
		);

		$listener->onObjectSaved((new ObjectEventPayload('blog', 'post-1', new ObjectData('post-1', [])))->toArray());
	}

	public function testRoutesObjectDeletedToProviderDelete(): void
	{
		$deletedCollection = null;
		$deletedId         = null;

		$provider = $this->makeProvider(
			'algolia',
			deleteCallback: function (string $c, string $id) use (&$deletedCollection, &$deletedId): void {
				$deletedCollection = $c;
				$deletedId         = $id;
			},
		);

		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$listener = new ContentChangeListener(
			$registry,
			$this->createMock(JobQueuer::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia', 'indexOnSave' => true]),
		);

		$listener->onObjectDeleted((new ObjectEventPayload('blog', 'post-1'))->toArray());

		$this->assertSame('blog', $deletedCollection);
		$this->assertSame('post-1', $deletedId);
	}

	/**
	 * The one test that cannot be fooled by a hand-built payload: dispatch a
	 * real ObjectEventPayload through a real EventDispatcher, the shape the
	 * container's registration delivers. This is how a key the payload does
	 * not carry (`object_id`, formerly) showed up as "never pushes" only in
	 * production.
	 */
	public function testPushesWhenDrivenByTheRealDispatcherAndPayload(): void
	{
		$seen     = [];
		$provider = $this->makeProvider(
			'algolia',
			indexCallback: function (string $c, string $id, array $data) use (&$seen): void {
				$seen[] = "index:$c/$id";
			},
			deleteCallback: function (string $c, string $id) use (&$seen): void {
				$seen[] = "delete:$c/$id";
			},
		);
		$registry = new SearchProviderRegistry();
		$registry->register($provider);
		$listener = new ContentChangeListener(
			$registry,
			$this->createMock(JobQueuer::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia', 'indexOnSave' => true]),
		);

		$dispatcher = new EventDispatcher(new NullLogger());
		$dispatcher->listen('object.created', $listener->onObjectSaved(...));
		$dispatcher->listen('object.updated', $listener->onObjectSaved(...));
		$dispatcher->listen('object.deleted', $listener->onObjectDeleted(...));

		$dispatcher->dispatch('object.created', new ObjectEventPayload('blog', 'post-1', new ObjectData('post-1', [])));
		$dispatcher->dispatch('object.updated', new ObjectEventPayload('blog', 'post-1', new ObjectData('post-1', []), new ObjectData('post-1', [])));
		$dispatcher->dispatch('object.deleted', new ObjectEventPayload('blog', 'post-1', null, new ObjectData('post-1', [])));

		$this->assertSame(['index:blog/post-1', 'index:blog/post-1', 'delete:blog/post-1'], $seen);
	}

	private function makeProvider(
		string $id,
		?\Closure $indexCallback = null,
		?\Closure $deleteCallback = null,
	): SearchProvider {
		return new class($id, $indexCallback, $deleteCallback) implements SearchProvider {
			public function __construct(
				private readonly string $id,
				private readonly ?\Closure $indexCallback,
				private readonly ?\Closure $deleteCallback,
			) {
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

			public function isAvailable(): bool
			{
				return true;
			}

			public function index(string $collection, string $id, array $data): void
			{
				if ($this->indexCallback instanceof \Closure) {
					($this->indexCallback)($collection, $id, $data);
				}
			}

			public function delete(string $collection, string $id): void
			{
				if ($this->deleteCallback instanceof \Closure) {
					($this->deleteCallback)($collection, $id);
				}
			}
		};
	}

	/** @param array<string,mixed> $search */
	private function makeConfig(array $search): Config
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($config, 'search'))->setValue($config, $search);

		return $config;
	}
}
