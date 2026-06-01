<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Per-automation runtime state at `.system/automations/<slug>.state.json`:
 * per-trigger last-fire timestamps and a consecutive-failure counter.
 */
final class AutomationStateStore
{
	public function __construct(private readonly StorageAdapterInterface $filesystem)
	{
	}

	public function lastFire(string $slug, string $triggerKey): ?string
	{
		$value = $this->load($slug)['lastFire'][$triggerKey] ?? null;

		return is_string($value) ? $value : null;
	}

	public function recordFire(string $slug, string $triggerKey, string $isoTime): void
	{
		$state                          = $this->load($slug);
		$lastFire                       = is_array($state['lastFire'] ?? null) ? $state['lastFire'] : [];
		$lastFire[$triggerKey]          = $isoTime;
		$state['lastFire']              = $lastFire;
		$this->save($slug, $state);
	}

	public function failures(string $slug): int
	{
		return (int)($this->load($slug)['failures'] ?? 0);
	}

	public function incrementFailures(string $slug): int
	{
		$state             = $this->load($slug);
		$count             = (int)($state['failures'] ?? 0) + 1;
		$state['failures'] = $count;
		$this->save($slug, $state);

		return $count;
	}

	public function resetFailures(string $slug): void
	{
		$state             = $this->load($slug);
		$state['failures'] = 0;
		$this->save($slug, $state);
	}

	/** @return array<string,mixed> */
	private function load(string $slug): array
	{
		$path = $this->path($slug);
		if (!$this->filesystem->fileExists($path)) {
			return [];
		}
		$data = json_decode($this->filesystem->read($path), true);

		return is_array($data) ? $data : [];
	}

	/** @param array<string,mixed> $state */
	private function save(string $slug, array $state): void
	{
		$json = (string)json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$tmp  = $this->path($slug) . '.tmp.' . bin2hex(random_bytes(4));
		$this->filesystem->write($tmp, $json);
		$this->filesystem->move($tmp, $this->path($slug));
	}

	private function path(string $slug): string
	{
		return '.system/automations/' . $slug . '.state.json';
	}
}
