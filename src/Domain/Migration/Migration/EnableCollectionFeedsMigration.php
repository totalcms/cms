<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Keeps blog and feed collections' RSS feeds working across the move to
 * per-collection feed settings.
 *
 * `/feed/rss/{collection}` used to serve every collection that had a URL, with
 * the query string choosing its fields. A collection now publishes a feed only
 * when its RSS Feed card is enabled. Blog and feed collections get the card
 * switched on here: those are the collections whose feed someone is all but
 * certainly subscribed to.
 *
 * Every other collection's feed is deliberately left off. That it was on, for
 * any collection with a URL, was the hole; an operator who wants one turns it
 * on in the collection's settings.
 *
 * A collection whose card already carries an `enabled` value, either way, is
 * left alone: that is someone's decision.
 */
readonly class EnableCollectionFeedsMigration implements MigrationInterface
{
	public function __construct(
		private CollectionLister $collectionLister,
		private CollectionSaver $collectionSaver,
		private SchemaFetcher $schemaFetcher,
		private LoggerInterface $logger,
	) {
	}

	public function id(): string
	{
		return 'enable-collection-feeds';
	}

	public function description(): string
	{
		return 'Enable the RSS feed on blog and feed collections.';
	}

	public function run(): int
	{
		$changed = 0;

		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if (array_key_exists('enabled', $collection->feed) || !$this->publishesFeed($collection)) {
				continue;
			}

			$this->collectionSaver->patchCollection($collection->id, ['feed' => ['enabled' => true] + $collection->feed]);
			$this->logger->info('Enabled the RSS feed on collection', [
				'collection' => $collection->id,
				'schema'     => $collection->schema,
			]);
			$changed++;
		}

		return $changed;
	}

	private function publishesFeed(CollectionData $collection): bool
	{
		if (in_array($collection->schema, RssBuilder::FEED_SCHEMAS, true)) {
			return true;
		}

		// A custom schema that inherits from blog or feed is one too.
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		} catch (\Throwable) {
			return false;
		}

		return array_intersect(RssBuilder::FEED_SCHEMAS, $schema->inheritFrom) !== [];
	}
}
