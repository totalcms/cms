<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Backup;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Backup\Listener\ObjectBackupListener;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Support\Config;

/**
 * The listener is the only writer on the lifecycle path, so what it forwards
 * — and when it stays silent — is the whole contract. It is driven through a
 * real EventDispatcher so the payload arrives in exactly the shape the
 * container's $lazy() registration delivers (toArray(), with `previous` as a
 * live ObjectData, not its array form).
 */
final class ObjectBackupListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private MockObject $store;
	private Config $config;

	protected function setUp(): void
	{
		$this->store      = $this->createMock(BackupStore::class);
		$this->config     = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->dispatcher = new EventDispatcher(new NullLogger());

		$listener = new ObjectBackupListener($this->store, $this->config);
		$this->dispatcher->listen('object.updated', $listener->onObjectUpdated(...));
		$this->dispatcher->listen('object.deleted', $listener->onObjectDeleted(...));
	}

	public function testUpdateSnapshotsThePreviousStateNotTheNewOne(): void
	{
		$previous = new ObjectData('post-1', []);
		$current  = new ObjectData('post-1', []);

		$this->store
			->expects($this->once())
			->method('snapshotObject')
			->with('posts', 'post-1', $previous->toArray());

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('posts', 'post-1', $current, $previous));
	}

	public function testDeleteSnapshotsTheFinalState(): void
	{
		$previous = new ObjectData('post-1', []);

		$this->store
			->expects($this->once())
			->method('snapshotObject')
			->with('posts', 'post-1', $previous->toArray());

		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('posts', 'post-1', null, $previous));
	}

	public function testFirstWriteWithNoPreviousIsSkipped(): void
	{
		$this->store->expects($this->never())->method('snapshotObject');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('posts', 'post-1', new ObjectData('post-1', [])));
	}

	public function testDeleteOfAnUnreadableRecordIsSkipped(): void
	{
		$this->store->expects($this->never())->method('snapshotObject');

		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('posts', 'post-1'));
	}

	public function testDisabledInSettingsWritesNothing(): void
	{
		$this->config->backups = ['enable' => false];
		$this->store->expects($this->never())->method('snapshotObject');

		$previous = new ObjectData('post-1', []);
		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('posts', 'post-1', $previous, $previous));
		$this->dispatcher->dispatch('object.deleted', new ObjectEventPayload('posts', 'post-1', null, $previous));
	}

	public function testEnabledByDefaultWhenSettingIsAbsent(): void
	{
		$this->config->backups = [];
		$previous              = new ObjectData('post-1', []);

		$this->store->expects($this->once())->method('snapshotObject');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('posts', 'post-1', $previous, $previous));
	}
}
