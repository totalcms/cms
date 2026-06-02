<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Data\AutomationContext;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPropertyIncrementer;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Service\DeckItemFetcher;
use TotalCMS\Domain\Property\Service\DeckItemRemover;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Property\Service\DeckItemUpdater;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Support\Config;

final class AutomationContextTest extends TestCase
{
	public function testExposesServicesAndPerTriggerPayloadSlots(): void
	{
		$config         = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$deckItemSaver  = $this->createMock(DeckItemSaver::class);
		$indexBuilder   = $this->createMock(IndexBuilder::class);
		$incrementer    = $this->createMock(ObjectPropertyIncrementer::class);

		$ctx = new AutomationContext(
			indexReader: $this->createMock(IndexReader::class),
			objectFetcher: $this->createMock(ObjectFetcher::class),
			objectSaver: $this->createMock(ObjectSaver::class),
			objectUpdater: $this->createMock(ObjectUpdater::class),
			objectRemover: $this->createMock(ObjectRemover::class),
			propertyIncrementer: $incrementer,
			objectCloner: $this->createMock(ObjectCloner::class),
			deckItemSaver: $deckItemSaver,
			deckItemUpdater: $this->createMock(DeckItemUpdater::class),
			deckItemRemover: $this->createMock(DeckItemRemover::class),
			deckItemFetcher: $this->createMock(DeckItemFetcher::class),
			indexBuilder: $indexBuilder,
			indexSearcher: $this->createMock(IndexSearcher::class),
			indexQueryService: $this->createMock(IndexQueryService::class),
			collectionFetcher: $this->createMock(CollectionFetcher::class),
			schemaFetcher: $this->createMock(SchemaFetcher::class),
			fileSaver: $this->createMock(FileSaver::class),
			imageSaver: $this->createMock(ImageSaver::class),
			csvImporter: $this->createMock(CsvImporter::class),
			jsonImporter: $this->createMock(JsonImporter::class),
			rssImporter: $this->createMock(RssImporter::class),
			syncService: $this->createMock(SyncService::class),
			mailer: $this->createMock(EmailService::class),
			config: $config,
			logger: new \Psr\Log\NullLogger(),
			trigger: ['type' => 'schedule', 'cron' => '0 1 * * *'],
			args: ['foo' => 'bar'],
		);

		// Per-trigger payload slots.
		expect($ctx->trigger['type'])->toBe('schedule');
		expect($ctx->args['foo'])->toBe('bar');
		expect($ctx->request)->toBeNull();
		expect($ctx->event)->toBeNull();

		// The expanded service surface is wired through verbatim.
		expect($ctx->deckItemSaver)->toBe($deckItemSaver);
		expect($ctx->indexBuilder)->toBe($indexBuilder);
		expect($ctx->propertyIncrementer)->toBe($incrementer);
	}
}
