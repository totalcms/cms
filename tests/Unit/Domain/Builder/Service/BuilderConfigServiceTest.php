<?php

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Support\Config;

final class BuilderConfigServiceTest extends TestCase
{
	private BuilderConfigService $service;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionSaver;
	private \PHPUnit\Framework\MockObject\MockObject $templateMigration;
	private \PHPUnit\Framework\MockObject\MockObject $templateFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $templateSaver;

	protected function setUp(): void
	{
		$this->config            = $this->createMock(Config::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionSaver   = $this->createMock(CollectionSaver::class);
		$this->templateMigration = $this->createMock(TemplateMigrationService::class);
		$this->templateFetcher   = $this->createMock(TemplateFetcher::class);
		$this->templateSaver     = $this->createMock(TemplateSaver::class);

		$this->config->builder = [];
		$this->config->docroot = '/var/www/html';

		$this->service = new BuilderConfigService(
			$this->config,
			$this->collectionFetcher,
			$this->collectionSaver,
			$this->templateMigration,
			$this->templateFetcher,
			$this->templateSaver,
		);
	}

	// --- getPagesCollectionId ---

	public function testDefaultPagesCollectionId(): void
	{
		$this->assertSame('builder-pages', $this->service->getPagesCollectionId());
	}

	public function testCustomPagesCollectionId(): void
	{
		$this->config->builder = ['pagesCollection' => 'my-pages'];

		$this->assertSame('my-pages', $this->service->getPagesCollectionId());
	}

	public function testFallsBackToDefaultForEmptyConfig(): void
	{
		$this->config->builder = ['pagesCollection' => ''];

		$this->assertSame('builder-pages', $this->service->getPagesCollectionId());
	}

	// --- ensurePagesCollection ---

	public function testCreatesDefaultCollectionWhenMissing(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(false);

		$this->collectionSaver->expects($this->once())
			->method('saveCollection')
			->with($this->callback(
				fn (array $data): bool => $data['id'] === 'builder-pages'
				&& $data['schema'] === 'builder-page'
				&& $data['name'] === 'Pages'
			));

		$this->service->ensurePagesCollection();
	}

	public function testSkipsCreationWhenCollectionExists(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$this->collectionSaver->expects($this->never())->method('saveCollection');

		$this->service->ensurePagesCollection();
	}

	public function testDoesNotAutoCreateCustomCollection(): void
	{
		$this->config->builder = ['pagesCollection' => 'custom-pages'];

		$this->collectionFetcher->method('collectionExists')->willReturn(false);

		$this->collectionSaver->expects($this->never())->method('saveCollection');

		$this->service->ensurePagesCollection();
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

		$this->service->ensureDefaultLayout();
	}

	public function testSkipsLayoutCreationWhenExists(): void
	{
		$this->templateFetcher->method('templateExists')
			->with('default', 'layouts')
			->willReturn(true);

		$this->templateSaver->expects($this->never())->method('saveTemplate');

		$this->service->ensureDefaultLayout();
	}

	// --- migrateFromTemplatesDir ---

	public function testDelegatesMigrationToService(): void
	{
		$this->templateMigration->expects($this->once())
			->method('migrateFromLegacyTemplates');

		$this->service->migrateFromTemplatesDir();
	}

	// --- pagesCollectionExists ---

	public function testPagesCollectionExistsReturnsTrue(): void
	{
		$this->collectionFetcher->method('collectionExists')
			->with('builder-pages')
			->willReturn(true);

		$this->assertTrue($this->service->pagesCollectionExists());
	}

	public function testPagesCollectionExistsReturnsFalse(): void
	{
		$this->collectionFetcher->method('collectionExists')
			->with('builder-pages')
			->willReturn(false);

		$this->assertFalse($this->service->pagesCollectionExists());
	}

	// --- getDocroot ---

	public function testGetDocroot(): void
	{
		$this->assertSame('/var/www/html', $this->service->getDocroot());
	}
}
