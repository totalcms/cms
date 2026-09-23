<?php

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\RemoteFileDownloader;
use TotalCMS\Support\Version;

/**
 * Import the entries of an RSS, Atom or JSON feed into a collection as
 * queued objects, or preview them.
 *
 * {@see FeedReader} turns whichever format arrives into one list of entries,
 * so there is a single pipeline here: fetch, parse, and for each entry mint
 * an id from the title, skip it if that object exists, map its fields onto
 * the collection, download its image, queue the import. It used to be two
 * pipelines (XML and JSON) with the sequence written out in each.
 */
class RssImporter
{
	private const USER_AGENT_TEMPLATE = 'TotalCMS/%s (+https://totalcms.co)';

	private const DEFAULT_FIELD_MAP = [
		'title'      => 'title',
		'content'    => 'content',
		'summary'    => 'summary',
		'date'       => 'date',
		'author'     => 'author',
		'categories' => 'categories',
		'link'       => 'media',
	];

	private readonly LoggerInterface $logger;
	private ?string $userAgent = null;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly JobQueuer $jobQueuer,
		private readonly HttpClientInterface $httpClient,
		private readonly RemoteFileDownloader $downloader,
		private readonly FeedReader $reader,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::RssImporter);
	}

	/**
	 * Preview a feed: its metadata and one row per entry.
	 *
	 * @return array{feed: array<string,mixed>, entries: list<array<string,mixed>>}
	 */
	public function analyze(string $feedUrl, ?string $userAgent = null): array
	{
		$this->userAgent = $userAgent === '' ? null : $userAgent;
		$this->logger->info(sprintf('Starting feed analysis: %s', $feedUrl));

		$feed = $this->reader->parse($this->fetchRawFeed($feedUrl));

		$this->logger->info(sprintf('Feed analysis completed: %d entries found', count($feed->entries)));

		return [
			'feed'    => $feed->summary(),
			'entries' => array_map(static fn (FeedEntry $entry): array => $entry->preview(), $feed->entries),
		];
	}

	/**
	 * @param array{draft?: bool, userAgent?: string, fieldMap?: array<string,string>} $options
	 *
	 * @return int entries queued for import
	 */
	public function import(string $feedUrl, string $collection, array $options = []): int
	{
		$isDraft         = $options['draft'] ?? true;
		$userAgent       = $options['userAgent'] ?? '';
		$this->userAgent = $userAgent === '' ? null : $userAgent;
		$fieldMap        = $options['fieldMap'] ?? [];

		$this->logger->info(sprintf('Starting feed import from %s into collection %s', $feedUrl, $collection));

		if (!$this->collectionFetcher->collectionExists($collection)) {
			throw new \RuntimeException(sprintf('Collection "%s" does not exist', $collection));
		}

		$feed  = $this->reader->parse($this->fetchRawFeed($feedUrl));
		$count = 0;

		foreach ($feed->entries as $entry) {
			if ($this->importEntry($entry, $collection, $isDraft, $fieldMap)) {
				$count++;
			}
		}

		$this->logger->info(sprintf('Feed import completed. Total items queued: %d', $count));

		return $count;
	}

	/**
	 * @param array<string,string> $fieldMap
	 */
	private function importEntry(FeedEntry $entry, string $collection, bool $isDraft, array $fieldMap): bool
	{
		try {
			$id = $this->slugify($entry->titleOrUntitled());

			if ($this->objectFetcher->existsObject($collection, $id)) {
				$this->logger->info(sprintf('Skipping feed entry, object already exists: %s/%s', $collection, $id));

				return false;
			}

			$data          = $this->mapFields($entry->toRecord(), $fieldMap);
			$data['id']    = $id;
			$data['draft'] = $isDraft;

			if ($entry->imageUrl !== null) {
				$tempPath = $this->downloadImage($entry->imageUrl);
				if ($tempPath !== null) {
					$data[$fieldMap['image'] ?? 'image'] = $tempPath;
				}
			}

			$this->jobQueuer->queueImport($collection, $data);
			$this->logger->info(sprintf('Queued feed entry import: %s/%s', $collection, $id));

			return true;
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing feed entry "%s": %s', $entry->title, $e->getMessage()));

			return false;
		}
	}

	private function fetchRawFeed(string $feedUrl): string
	{
		try {
			$response = $this->httpClient->request('GET', $feedUrl, $this->requestOptions(30));
		} catch (\RuntimeException $e) {
			throw new \RuntimeException(sprintf('Failed to fetch feed from %s: %s', $feedUrl, $e->getMessage()), 0, $e);
		}

		if ($response->statusCode !== 200) {
			throw new \RuntimeException(sprintf('Failed to fetch feed: HTTP %d', $response->statusCode));
		}

		if (trim($response->body) === '') {
			throw new \RuntimeException('Feed returned empty response');
		}

		return $response->body;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function requestOptions(int $timeout): array
	{
		return [
			'timeout'          => $timeout,
			'verify_ssl'       => false,
			'follow_redirects' => true,
			'user_agent'       => $this->userAgent ?? sprintf(self::USER_AGENT_TEMPLATE, Version::number()),
		];
	}

	/**
	 * Rename the entry's fields to the collection's. The default map sends
	 * each field to a property of the same name and the link to `media`; an
	 * empty target drops the field.
	 *
	 * @param array<string,mixed>  $record
	 * @param array<string,string> $fieldMap
	 *
	 * @return array<string,mixed>
	 */
	private function mapFields(array $record, array $fieldMap): array
	{
		$mapped = [];
		foreach (array_merge(self::DEFAULT_FIELD_MAP, $fieldMap) as $feedField => $collectionField) {
			if ($collectionField === '' || !isset($record[$feedField])) {
				continue;
			}
			$mapped[$collectionField] = $record[$feedField];
		}

		return $mapped;
	}

	private function downloadImage(string $url): ?string
	{
		try {
			$tempFile = $this->downloader->download($url, [
				'timeout'    => 15,
				'prefix'     => 'rss-import',
				'user_agent' => $this->requestOptions(15)['user_agent'],
			]);
			$this->logger->info(sprintf('Downloaded image: %s → %s', $url, $tempFile));

			return $tempFile;
		} catch (\RuntimeException $e) {
			$this->logger->warning(sprintf('Error downloading image %s: %s', $url, $e->getMessage()));

			return null;
		}
	}

	private function slugify(string $text): string
	{
		// The same slug every other importer and the id field mint from a
		// title, so an imported entry's id matches what T3 would derive itself.
		$slug = SlugData::slugify($text);

		return $slug === '' ? 'untitled-' . uniqid() : $slug;
	}
}
