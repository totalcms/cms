<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Job;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Support\Config;

/**
 * Retries a failed index/delete operation against the active search
 * provider. Enqueued by ContentChangeListener when a provider throws
 * during normal event-driven indexing; also enqueued en masse by the
 * `tcms search:reindex` CLI.
 *
 * Payload (JSON-encoded in JobData::$payload):
 *   - object_id: the object id
 *   - operation: 'index' or 'delete'
 *
 * Returns silently on success. Throws on failure so JobRunner re-enqueues
 * per its standard retry policy.
 */
readonly class ReindexJob
{
	public function __construct(
		private SearchProviderRegistry $registry,
		private ObjectFetcher $objects,
		private LoggerInterface $logger,
		private Config $config,
	) {
	}

	public function run(JobData $job): void
	{
		$activeId = (string)($this->config->search['activeProvider'] ?? 'text');
		if ($activeId === 'text') {
			// Operator switched back to text; the queued job is irrelevant.
			$this->logger->info('ReindexJob skipped: active provider is text', [
				'job_id'     => $job->id,
				'collection' => $job->collection,
			]);

			return;
		}

		$provider = $this->registry->active($activeId);
		if (!$provider instanceof \TotalCMS\Domain\Search\Service\SearchProvider) {
			throw new \RuntimeException(sprintf(
				'ReindexJob: active provider "%s" not registered',
				$activeId,
			));
		}

		$payload = json_decode($job->payload, true);
		if (!is_array($payload)) {
			throw new \RuntimeException('ReindexJob: invalid JSON payload');
		}

		$objectId  = (string)($payload['object_id'] ?? '');
		$operation = (string)($payload['operation'] ?? 'index');

		if ($objectId === '') {
			throw new \RuntimeException('ReindexJob: missing object_id in payload');
		}

		if ($operation === 'delete') {
			$provider->delete($job->collection, $objectId);

			return;
		}

		// operation === 'index'
		if (!$this->objects->existsObject($job->collection, $objectId)) {
			// Object was deleted between listener firing and job processing —
			// keep the provider in sync with T3's truth by issuing a delete.
			$provider->delete($job->collection, $objectId);

			return;
		}

		$object = $this->objects->fetchObject($job->collection, $objectId);
		$provider->index($job->collection, $objectId, $object->toArray());
	}
}
