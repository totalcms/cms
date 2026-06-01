<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * The automations-only async lane. Webhook (async) and event triggers enqueue a
 * pending run here; `tcms automations:process` drains it on the next tick. Kept
 * separate from the import JobQueue so an import backlog never delays an
 * automation.
 *
 * The PSR-7 request is intentionally NOT serialized — async handlers use
 * `$ctx->args`; raw-request access requires a `sync: true` webhook.
 */
final class AutomationQueue
{
	private const DIR = '.system/automations/_queue';

	public function __construct(private readonly StorageAdapterInterface $filesystem)
	{
	}

	/**
	 * @param array<string,mixed> $trigger
	 * @param array<string,mixed> $args
	 * @param array<string,mixed>|null $event
	 */
	public function enqueue(string $slug, array $trigger, array $args, ?array $event = null): string
	{
		// Time-prefixed id → lexical sort == roughly FIFO drain order.
		$runId = gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(6));
		$job   = ['runId' => $runId, 'slug' => $slug, 'trigger' => $trigger, 'args' => $args, 'event' => $event];

		$this->filesystem->write(self::DIR . '/' . $runId . '.json', (string)json_encode($job, JSON_UNESCAPED_SLASHES));

		return $runId;
	}

	/**
	 * Run each pending job through the handler, oldest first. Each job file is
	 * deleted BEFORE the handler runs — a throwing handler is captured as a
	 * failed run record by the runner, never re-queued into an infinite loop.
	 *
	 * @param callable(array<string,mixed>):void $handle
	 */
	public function drain(callable $handle): void
	{
		$files = $this->filesystem->listFiles(self::DIR);
		sort($files);

		foreach ($files as $path) {
			if (!str_ends_with($path, '.json')) {
				continue;
			}

			$job = json_decode($this->filesystem->read($path), true);
			$this->filesystem->delete($path);

			if (is_array($job)) {
				$handle($job);
			}
		}
	}
}
