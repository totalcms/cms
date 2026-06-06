<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Assembles the {@see AutomationContext} handed to every automation handler.
 *
 * The context's service surface is broad (content + deck CRUD, querying, import,
 * sync, …), so it lives here rather than in AutomationRunner's constructor — the
 * runner stays focused on orchestration and asks the factory for a context per
 * run, supplying only the per-trigger payload.
 */
final readonly class AutomationContextFactory
{
	private LoggerInterface $logger;

	public function __construct(
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private ObjectSaver $objectSaver,
		private ObjectUpdater $objectUpdater,
		private ObjectRemover $objectRemover,
		private ObjectPropertyIncrementer $propertyIncrementer,
		private ObjectCloner $objectCloner,
		private DeckItemSaver $deckItemSaver,
		private DeckItemUpdater $deckItemUpdater,
		private DeckItemRemover $deckItemRemover,
		private DeckItemFetcher $deckItemFetcher,
		private IndexBuilder $indexBuilder,
		private IndexSearcher $indexSearcher,
		private IndexQueryService $indexQueryService,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private FileSaver $fileSaver,
		private ImageSaver $imageSaver,
		private CsvImporter $csvImporter,
		private JsonImporter $jsonImporter,
		private RssImporter $rssImporter,
		private SyncService $syncService,
		private EmailService $mailer,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Automations);
	}

	/**
	 * @param array<string,mixed> $trigger the trigger row that fired this run
	 * @param array<string,mixed> $args caller inputs (webhook query+body / manual run args)
	 * @param array<string,mixed>|null $event event payload (event triggers only)
	 */
	public function create(array $trigger = [], array $args = [], ?ServerRequestInterface $request = null, ?array $event = null): AutomationContext
	{
		return new AutomationContext(
			indexReader: $this->indexReader,
			objectFetcher: $this->objectFetcher,
			objectSaver: $this->objectSaver,
			objectUpdater: $this->objectUpdater,
			objectRemover: $this->objectRemover,
			propertyIncrementer: $this->propertyIncrementer,
			objectCloner: $this->objectCloner,
			deckItemSaver: $this->deckItemSaver,
			deckItemUpdater: $this->deckItemUpdater,
			deckItemRemover: $this->deckItemRemover,
			deckItemFetcher: $this->deckItemFetcher,
			indexBuilder: $this->indexBuilder,
			indexSearcher: $this->indexSearcher,
			indexQueryService: $this->indexQueryService,
			collectionFetcher: $this->collectionFetcher,
			schemaFetcher: $this->schemaFetcher,
			fileSaver: $this->fileSaver,
			imageSaver: $this->imageSaver,
			csvImporter: $this->csvImporter,
			jsonImporter: $this->jsonImporter,
			rssImporter: $this->rssImporter,
			syncService: $this->syncService,
			mailer: $this->mailer,
			config: $this->config,
			logger: $this->logger,
			trigger: $trigger,
			args: $args,
			request: $request,
			event: $event,
		);
	}
}
