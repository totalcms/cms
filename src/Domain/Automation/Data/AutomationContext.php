<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Data;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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

/**
 * The only API surface an automation handler receives. Pre-injected services
 * plus per-trigger payload data. Schedule runs leave `request`/`event` null;
 * webhook runs populate `request`, event runs populate `event`.
 */
final readonly class AutomationContext
{
	/**
	 * @param array<string,mixed> $trigger the trigger row that fired this run
	 * @param array<string,mixed> $args caller inputs (webhook query+body / manual run args)
	 * @param array<string,mixed>|null $event event payload (event triggers only)
	 */
	public function __construct(
		public IndexReader $indexReader,
		public ObjectFetcher $objectFetcher,
		public ObjectSaver $objectSaver,
		public ObjectUpdater $objectUpdater,
		public ObjectRemover $objectRemover,
		public ObjectPropertyIncrementer $propertyIncrementer,
		public ObjectCloner $objectCloner,
		public DeckItemSaver $deckItemSaver,
		public DeckItemUpdater $deckItemUpdater,
		public DeckItemRemover $deckItemRemover,
		public DeckItemFetcher $deckItemFetcher,
		public IndexBuilder $indexBuilder,
		public IndexSearcher $indexSearcher,
		public IndexQueryService $indexQueryService,
		public CollectionFetcher $collectionFetcher,
		public SchemaFetcher $schemaFetcher,
		public FileSaver $fileSaver,
		public ImageSaver $imageSaver,
		public CsvImporter $csvImporter,
		public JsonImporter $jsonImporter,
		public RssImporter $rssImporter,
		public SyncService $syncService,
		public EmailService $mailer,
		public Config $config,
		public LoggerInterface $logger,
		public array $trigger = [],
		public array $args = [],
		public ?ServerRequestInterface $request = null,
		public ?array $event = null,
	) {
	}
}
