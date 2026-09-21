<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Listener;

use Monolog\Level;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Pushes object lifecycle events to the active search provider's
 * index()/delete() methods. Skips when:
 *   - the active provider is 'text' (IndexBuilder already handles it)
 *   - indexOnSave is disabled (operator-managed bulk imports)
 *   - the active provider isn't registered (no-op silently).
 *
 * Index failures are caught and enqueued as `search.reindex` jobs for
 * eventual retry. Content saves are never blocked by provider outages.
 */
readonly class ContentChangeListener
{
	private LoggerInterface $logger;

	public function __construct(
		private SearchProviderRegistry $registry,
		private JobQueuer $jobs,
		LoggerFactory $loggerFactory,
		private Config $config,
	) {
		// Inject LoggerFactory (autowirable) rather than the bare
		// Psr\Log\LoggerInterface, which has no concrete container binding —
		// autowiring the interface made this listener unresolvable, so every
		// object.created/updated/deleted dispatch logged a DI error instead
		// of pushing the change to the active search provider (same failure
		// family as the ReindexJob/jobs:process crash). Search channel
		// matches SearchService.
		$this->logger = $loggerFactory->channelLogger(LogChannel::Search, Level::Info);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function onObjectSaved(array $payload): void
	{
		if (!$this->shouldPush()) {
			return;
		}
		$provider = $this->activeProvider();
		if (!$provider instanceof SearchProvider) {
			return;
		}

		// ObjectEventPayload::toArray() carries the id as `id` and the record
		// as a live ObjectData. This read `object_id` and cast the object with
		// (array) — so the id was always '' and every save returned here
		// before reaching the provider; indexOnSave had never pushed a thing.
		$collection = (string)($payload['collection'] ?? '');
		$objectId   = (string)($payload['id'] ?? '');
		$object     = $payload['object'] ?? [];
		$object     = $object instanceof ObjectData ? $object->toArray() : (array)$object;

		if ($collection === '' || $objectId === '') {
			return;
		}

		try {
			$provider->index($collection, $objectId, $object);
		} catch (\Throwable $e) {
			$this->logger->warning('Search provider index() threw; enqueueing retry', [
				'provider'   => $provider->id(),
				'collection' => $collection,
				'object_id'  => $objectId,
				'error'      => $e->getMessage(),
			]);
			$this->jobs->queueJob('search.reindex', $collection, [
				'object_id' => $objectId,
				'operation' => 'index',
			]);
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function onObjectDeleted(array $payload): void
	{
		if (!$this->shouldPush()) {
			return;
		}
		$provider = $this->activeProvider();
		if (!$provider instanceof SearchProvider) {
			return;
		}

		$collection = (string)($payload['collection'] ?? '');
		$objectId   = (string)($payload['id'] ?? '');

		if ($collection === '' || $objectId === '') {
			return;
		}

		try {
			$provider->delete($collection, $objectId);
		} catch (\Throwable $e) {
			$this->logger->warning('Search provider delete() threw; enqueueing retry', [
				'provider'   => $provider->id(),
				'collection' => $collection,
				'object_id'  => $objectId,
				'error'      => $e->getMessage(),
			]);
			$this->jobs->queueJob('search.reindex', $collection, [
				'object_id' => $objectId,
				'operation' => 'delete',
			]);
		}
	}

	private function shouldPush(): bool
	{
		return (bool)($this->config->search['indexOnSave'] ?? true);
	}

	private function activeProvider(): ?SearchProvider
	{
		$activeId = (string)($this->config->search['activeProvider'] ?? 'text');
		if ($activeId === 'text') {
			return null; // IndexBuilder handles the text path
		}

		return $this->registry->active($activeId);
	}
}
