<?php

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Domain\Template\Service\TemplateSaver;

final class BuilderInstallerTest extends TestCase
{
	private BuilderInstaller $installer;
	private \PHPUnit\Framework\MockObject\MockObject $builderConfig;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionSaver;
	private \PHPUnit\Framework\MockObject\MockObject $templateMigration;
	private \PHPUnit\Framework\MockObject\MockObject $templateFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $templateSaver;

	protected function setUp(): void
	{
		$this->builderConfig     = $this->createMock(BuilderConfigService::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionSaver   = $this->createMock(CollectionSaver::class);
		$this->templateMigration = $this->createMock(TemplateMigrationService::class);
		$this->templateFetcher   = $this->createMock(TemplateFetcher::class);
		$this->templateSaver     = $this->createMock(TemplateSaver::class);

		$this->installer = new BuilderInstaller(
			$this->builderConfig,
			$this->collectionFetcher,
			$this->collectionSaver,
			$this->templateMigration,
			$this->templateFetcher,
			$this->templateSaver,
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

	// --- ensureDefaultLayout ---

	public function testCreatesDefaultLayoutWhenMissing(): void
	{
		$this->templateFetcher->method('templateExists')
			->with('default', 'layouts')
			->willReturn(false);

		$this->templateSaver->expects($this->once())
			->method('saveTemplate')
			->with('default', $this->stringContains('<!DOCTYPE html>'), 'layouts');

		$this->installer->ensureDefaultLayout();
	}

	public function testSkipsLayoutCreationWhenExists(): void
	{
		$this->templateFetcher->method('templateExists')
			->with('default', 'layouts')
			->willReturn(true);

		$this->templateSaver->expects($this->never())->method('saveTemplate');

		$this->installer->ensureDefaultLayout();
	}

	// --- migrateFromTemplatesDir ---

	public function testDelegatesMigrationToService(): void
	{
		$this->templateMigration->expects($this->once())
			->method('migrateFromLegacyTemplates');

		$this->installer->migrateFromTemplatesDir();
	}
}
