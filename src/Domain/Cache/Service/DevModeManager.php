<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\SystemEventPayload;

/**
 * Manages temporary development mode state.
 */
class DevModeManager
{
	private readonly string $devModeFile;
	private int $devModeDuration = 10800; // 3 hours in seconds

	public function __construct(
		private readonly EventDispatcher $eventDispatcher,
	) {
		$this->devModeFile = sys_get_temp_dir() . '/totalcms_devmode.json';
	}

	/**
	 * Enable development mode for specified duration.
	 */
	public function enableDevMode(): void
	{
		$devModeData = [
			'enabled'    => true,
			'expires_at' => time() + $this->devModeDuration,
			'started_at' => time(),
		];

		file_put_contents(
			$this->devModeFile,
			json_encode($devModeData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
		);

		$this->eventDispatcher->dispatch('devmode.enabled', new SystemEventPayload([
			'duration' => $this->devModeDuration,
		]));
	}

	/**
	 * Disable development mode.
	 */
	public function disableDevMode(): void
	{
		if (file_exists($this->devModeFile)) {
			unlink($this->devModeFile);
		}

		$this->eventDispatcher->dispatch('devmode.disabled', new SystemEventPayload());
	}

	/**
	 * Check if development mode is currently active.
	 */
	public function isDevModeActive(): bool
	{
		if (!file_exists($this->devModeFile)) {
			return false;
		}

		try {
			$content = file_get_contents($this->devModeFile);
			if ($content === false) {
				return false;
			}

			$devModeData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

			if (!isset($devModeData['enabled'], $devModeData['expires_at'])) {
				return false;
			}

			// Check if expired
			if (time() > $devModeData['expires_at']) {
				$this->disableDevMode();

				return false;
			}

			return $devModeData['enabled'];
		} catch (\JsonException) {
			// Invalid JSON, remove the file
			$this->disableDevMode();

			return false;
		}
	}

	/**
	 * Get remaining time in seconds until dev mode expires.
	 */
	public function getRemainingTime(): int
	{
		if (!$this->isDevModeActive()) {
			return 0;
		}

		try {
			$content = file_get_contents($this->devModeFile);
			if ($content === false) {
				return 0;
			}

			$devModeData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
			$remaining   = $devModeData['expires_at'] - time();

			return (int)max(0, $remaining);
		} catch (\JsonException) {
			return 0;
		}
	}

	/**
	 * Get development mode status information.
	 *
	 * @return array<string,mixed>
	 */
	public function getDevModeStatus(): array
	{
		if (!$this->isDevModeActive()) {
			return [
				'enabled'             => false,
				'remaining_seconds'   => 0,
				'remaining_formatted' => '0:00:00',
				'expires_at'          => null,
				'started_at'          => null,
			];
		}

		try {
			$content = file_get_contents($this->devModeFile);
			if ($content === false) {
				return $this->getDevModeStatus(); // Recurse to get disabled state
			}

			$devModeData      = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
			$remainingSeconds = $this->getRemainingTime();

			return [
				'enabled'             => true,
				'remaining_seconds'   => $remainingSeconds,
				'remaining_formatted' => $this->formatTime($remainingSeconds),
				'expires_at'          => $devModeData['expires_at'],
				'started_at'          => $devModeData['started_at'],
			];
		} catch (\JsonException) {
			return $this->getDevModeStatus(); // Recurse to get disabled state
		}
	}

	/**
	 * Format seconds into HH:MM:SS format.
	 */
	private function formatTime(int $seconds): string
	{
		$hours   = intval($seconds / 3600);
		$minutes = intval(($seconds % 3600) / 60);
		$seconds %= 60;

		return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
	}
}
