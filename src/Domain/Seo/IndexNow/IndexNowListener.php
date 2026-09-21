<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\IndexNow;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Sitemap\Service\SitemapUrlResolver;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Turns object lifecycle events into pending IndexNow submissions —
 * appended to the outbox, never sent here: a save must not wait on a
 * search engine, and everything between two queue runs goes out as one
 * request.
 *
 * The URL comes from the sitemap's own rules (see SitemapUrlResolver), so a
 * draft, a noindexed page or a collection with its sitemap off submits
 * nothing. A change that takes a URL OUT of the sitemap — a post moved back
 * to draft, or deleted — is submitted too: the engines are told the URL
 * changed and find the 404 or noindex on recrawl, which is the fastest way
 * to have it dropped.
 *
 * Imports count as well — a CSV that publishes a hundred posts is exactly
 * when the engines should hear about them. The dispatcher replaces
 * object.created/updated with import.created/updated for a collection
 * mid-import, so those are subscribed separately, and they are buffered
 * here rather than written per record: the outbox is a locked read-rewrite
 * of one file, fine per save, wasteful ten thousand times in a loop. The
 * buffer flushes on import.completed, whenever a collection's buffer
 * reaches BUFFER_FLUSH, and at process end — the last for the importers
 * that fire per-object import events with no completion event (deck
 * imports, single objects run through the job queue).
 */
class IndexNowListener
{
	/** Import URLs buffered per collection before they reach the outbox. */
	private const BUFFER_FLUSH = 100;

	/** @var array<string,array<string,true>> collection → set of URLs */
	private array $pending = [];

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly SitemapUrlResolver $resolver,
		private readonly IndexNowSubmitter $submitter,
		private readonly IndexNowOutbox $outbox,
		private readonly JobRepository $jobRepository,
		private readonly JobQueuer $jobs,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::IndexNow);
	}

	public function __destruct()
	{
		$this->flushAll();
	}

	/** @param array<string,mixed> $payload */
	public function onObjectSaved(array $payload): void
	{
		$this->enqueue($this->urlsFor($payload, [$payload['object'] ?? null, $payload['previous'] ?? null]));
	}

	/** @param array<string,mixed> $payload */
	public function onObjectDeleted(array $payload): void
	{
		$this->enqueue($this->urlsFor($payload, [$payload['previous'] ?? null]));
	}

	/**
	 * import.created / import.updated — same payload shape as the object
	 * events, buffered instead of written.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function onImportSaved(array $payload): void
	{
		$collection = (string)($payload['collection'] ?? '');
		foreach ($this->urlsFor($payload, [$payload['object'] ?? null, $payload['previous'] ?? null]) as $url) {
			$this->pending[$collection][$url] = true;
		}

		if (count($this->pending[$collection] ?? []) >= self::BUFFER_FLUSH) {
			$this->flush($collection);
		}
	}

	/** @param array<string,mixed> $payload */
	public function onImportCompleted(array $payload): void
	{
		$this->flush((string)($payload['collection'] ?? ''));
	}

	/**
	 * The sitemap URLs the given object states resolve to, deduplicated.
	 * Resolved before Site SEO is consulted — most saves resolve to nothing
	 * (drafts, collections with no sitemap, the Site SEO record itself) and
	 * never need the settings loaded. That matters: the loader memoises for
	 * the request, and reading it during the save of the seo-site record
	 * would pin the pre-save values for everything rendered afterwards.
	 *
	 * @param array<string,mixed> $payload
	 * @param list<mixed>         $states
	 *
	 * @return list<string>
	 */
	private function urlsFor(array $payload, array $states): array
	{
		$collection = (string)($payload['collection'] ?? '');
		if ($collection === '') {
			return [];
		}

		$urls = [];
		foreach ($states as $state) {
			if (!$state instanceof ObjectData) {
				continue;
			}
			$url = $this->resolver->urlFor($collection, $state->toArray());
			if ($url !== null) {
				$urls[$url] = true;
			}
		}

		if ($urls === []) {
			// Debug, not info: this is the common case for every draft save and
			// every collection without a sitemap. It is here so "I enabled it
			// and nothing happened" can be answered from the log — and the
			// answer is nearly always the sitemap card's exclude filter.
			$this->logger->debug('IndexNow: nothing to submit — the sitemap would not list this record', [
				'collection' => $collection,
				'id'         => (string)($payload['id'] ?? ''),
			]);
		}

		return array_keys($urls);
	}

	private function flush(string $collection): void
	{
		$urls = array_keys($this->pending[$collection] ?? []);
		unset($this->pending[$collection]);
		$this->enqueue($urls);
	}

	private function flushAll(): void
	{
		foreach (array_keys($this->pending) as $collection) {
			$this->flush($collection);
		}
	}

	/**
	 * Into the outbox, and make sure one job is pending to drain it. A second
	 * job would only be noise; two saves racing past the check can both queue
	 * one, and the later job finds the outbox empty and returns.
	 *
	 * @param list<string> $urls
	 */
	private function enqueue(array $urls): void
	{
		if ($urls === [] || !$this->submitter->isConfigured()) {
			return;
		}

		$this->outbox->add($urls);

		if (!$this->jobRepository->hasPendingJob(JobData::TYPE_INDEXNOW, IndexNowOutbox::JOB_COLLECTION)) {
			$this->jobs->queueJob(JobData::TYPE_INDEXNOW, IndexNowOutbox::JOB_COLLECTION);
		}
	}
}
