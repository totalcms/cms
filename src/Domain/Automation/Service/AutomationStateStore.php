<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Per-automation runtime state at `.system/automations/<id>.state.json`:
 * per-trigger last-fire timestamps and a consecutive-failure counter.
 */
final class AutomationStateStore
{
	public function __construct(private readonly StorageAdapterInterface $filesystem)
	{
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
		$path = $this->path($id);
		if (!$this->filesystem->fileExists($path)) {
			return [];
		}
		$data = json_decode($this->filesystem->read($path), true);

		return is_array($data) ? $data : [];
	}

	/** @param array<string,mixed> $state */
	private function save(string $id, array $state): void
	{
		$json = (string)json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$tmp  = $this->path($id) . '.tmp.' . bin2hex(random_bytes(4));
		$this->filesystem->write($tmp, $json);
		$this->filesystem->move($tmp, $this->path($id));
	}

	private function path(string $id): string
	{
		return '.system/automations/' . $id . '.state.json';
	}
}
