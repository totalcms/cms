<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Template\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Exception\TemplatesLockedException;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Domain\Template\Service\TemplateSnapshotService;

final class TemplateSaverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $snapshots;
	private EventDispatcher $events;

	protected function setUp(): void
	{
		$this->storage   = $this->createMock(TemplateRepository::class);
		$this->snapshots = $this->createMock(TemplateSnapshotService::class);
		// EventDispatcher is final; a real one with a NullLogger is a harmless
		// no-op (no listeners registered).
		$this->events    = new EventDispatcher(new \Psr\Log\NullLogger());
	}

	private function makeSaver(bool $locked): TemplateSaver
	{
		$paths = $this->createMock(BuilderTemplatePaths::class);
		$paths->method('locked')->willReturn($locked);

		return new TemplateSaver($this->storage, $this->snapshots, $this->events, $paths);
	}

	public function testSaveTemplateSavesWhenUnlocked(): void
	{
		$this->storage->method('reservedTemplateExists')->willReturn(false);
		$this->storage->method('fetchBuilderTemplate')->willReturn(null);
		$this->storage->expects($this->once())->method('saveTemplate');

		$result = $this->makeSaver(false)->saveTemplate('about', '<h1>x</h1>', 'pages');

		$this->assertSame('about', $result->id);
	}

	public function testSaveTemplateThrowsWhenLocked(): void
	{
		// Locked = git-managed; no write should reach storage.
		$this->storage->expects($this->never())->method('saveTemplate');

		$this->expectException(TemplatesLockedException::class);
		$this->makeSaver(true)->saveTemplate('about', '<h1>x</h1>', 'pages');
	}

	public function testSaveDesignerMetaThrowsWhenLocked(): void
	{
		$this->storage->expects($this->never())->method('saveDesignerMeta');

		$this->expectException(TemplatesLockedException::class);
		$this->makeSaver(true)->saveDesignerMeta('about', 'pages', new DesignerMetadata());
	}
}
