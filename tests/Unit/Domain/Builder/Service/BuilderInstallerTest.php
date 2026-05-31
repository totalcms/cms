<?php

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;

final class BuilderInstallerTest extends TestCase
{
	private BuilderInstaller $installer;
	private \PHPUnit\Framework\MockObject\MockObject $builderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionSaver;
	private \PHPUnit\Framework\MockObject\MockObject $templateMigration;

	protected function setUp(): void
	{
		$this->builderConfig     = $this->createMock(BuilderConfigService::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionSaver   = $this->createMock(CollectionSaver::class);
		$this->templateMigration = $this->createMock(TemplateMigrationService::class);

		$this->installer = new BuilderInstaller(
			$this->builderConfig,
			$this->collectionFetcher,
			$this->collectionSaver,
			$this->templateMigration,
		);
	}

	// --- ensurePagesCollection ---

	public function testCreatesDefaultCollectionWhenMissing(): void
	{
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->collectionFetcher->method('collectionExists')->willReturn(false);

		$this->collectionSaver->expects($this->once())
			->method('saveCollection')
			->with($this->callback(
				fn (array $data): bool => $data['id'] === 'builder-pages'
				&& $data['schema'] === 'builder-page'
				&& $data['name'] === 'Pages'
			));

		$this->installer->ensurePagesCollection();
	}

	public function testSkipsCreationWhenCollectionExists(): void
	{
		$this->builderConfig->method('getPagesCollectionId')->willReturn('builder-pages');
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$this->collectionSaver->expects($this->never())->method('saveCollection');

		$this->installer->ensurePagesCollection();
	}

	public function testDoesNotAutoCreateCustomCollection(): void
	{
		$this->builderConfig->method('getPagesCollectionId')->willReturn('custom-pages');
		$this->collectionFetcher->method('collectionExists')->willReturn(false);

		$this->collectionSaver->expects($this->never())->method('saveCollection');

		$this->installer->ensurePagesCollection();
	}

	// --- migrateFromTemplatesDir ---

	public function testDelegatesMigrationToService(): void
	{
		$this->templateMigration->expects($this->once())
			->method('migrateFromLegacyTemplates');

		$this->installer->migrateFromTemplatesDir();
	}
}
