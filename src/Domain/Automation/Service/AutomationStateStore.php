<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Per-automation runtime state at `.system/automations/<id>.state.json`:
 * per-trigger last-fire timestamps and a consecutive-failure counter.
 */
final readonly class AutomationStateStore
{
	private AtomicJsonStore $store;

	public function __construct(StorageAdapterInterface $filesystem, ?AtomicJsonStore $store = null)
	{
		$this->store = $store ?? new AtomicJsonStore($filesystem, '', new NullLogger());
	}

	public function lastFire(string $id, string $triggerKey): ?string
	{
		$value = $this->load($id)['lastFire'][$triggerKey] ?? null;

		return is_string($value) ? $value : null;
	}

	public function recordFire(string $id, string $triggerKey, string $isoTime): void
	{
		$state                          = $this->load($id);
		$lastFire                       = is_array($state['lastFire'] ?? null) ? $state['lastFire'] : [];
		$lastFire[$triggerKey]          = $isoTime;
		$state['lastFire']              = $lastFire;
		$this->save($id, $state);
	}

	public function failures(string $id): int
	{
		return (int)($this->load($id)['failures'] ?? 0);
	}

	public function incrementFailures(string $id): int
	{
		$state             = $this->load($id);
		$count             = (int)($state['failures'] ?? 0) + 1;
		$state['failures'] = $count;
		$this->save($id, $state);

		return $count;
	}

	public function resetFailures(string $id): void
	{
		$state             = $this->load($id);
		$state['failures'] = 0;
		$this->save($id, $state);
	}

	/** @return array<string,mixed> */
	private function load(string $id): array
	{
		// RefuseWrites per file: a broken state file means "no history" for
		// this run, and nothing is written back until it is repaired or
		// removed — better than silently resetting the auto-disable counter.
		return $this->store->load($this->path($id), CorruptPolicy::RefuseWrites);
	}

	/** @param array<string,mixed> $state */
	private function save(string $id, array $state): void
	{
		$this->store->save($this->path($id), $state);
	}

	private function path(string $id): string
	{
		return '.system/automations/' . $id . '.state.json';
	}
}
