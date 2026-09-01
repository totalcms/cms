<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cache\Service;

use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\SystemEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Support\Config;

/**
 * Manages temporary development mode state.
 */
class DevModeManager
{
	private readonly string $devModeFile;
	private int $devModeDuration = 10800; // 3 hours in seconds

	public function __construct(
		private readonly EventDispatcher $eventDispatcher,
		Config $config,
	) {
		// Per-install path under the data dir — NOT sys_get_temp_dir(). On shared
		// hosting /tmp is one directory for every tenant, so a global filename
		// like /tmp/totalcms_devmode.json collides across all sites on the box:
		// whichever PHP user writes it first owns it, and /tmp's sticky bit then
		// gives every other site EPERM ("Operation not permitted") on unlink —
		// which, promoted to an ErrorException, hard-crashed container build.
		$this->devModeFile = $config->systemDir() . '/totalcms_devmode.json';
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

		$dir = dirname($this->devModeFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}

		$json = json_encode($devModeData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

		// Write-then-rename so a concurrent reader never sees a half-written file.
		// A torn read is invalid JSON, and every reader below treats that as
		// "dev mode is off" — so a plain file_put_contents could flip dev mode off
		// under a request that happens to read mid-write. rename() is atomic within
		// a filesystem: readers see the old state or the new one, never a partial.
		// The pid keeps the temp name unique without depending on the clock.
		$tmp = $this->devModeFile . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $this->devModeFile)) {
			@unlink($tmp);
			// Direct write as a fallback: a non-atomic enable still beats no enable.
			@file_put_contents($this->devModeFile, $json);
		}

		$this->eventDispatcher->dispatch(CoreEvent::DEVMODE_ENABLED, new SystemEventPayload([
			'duration' => $this->devModeDuration,
		]));
	}

	/**
	 * Disable development mode.
	 */
	public function disableDevMode(): void
	{
		// Best-effort: a failed cleanup of a non-critical dev flag must never take
		// down the request (or, as here, container construction). Suppressed so a
		// filesystem permission quirk can't be promoted to a fatal ErrorException.
		if (is_file($this->devModeFile)) {
			@unlink($this->devModeFile);
		}

		$this->eventDispatcher->dispatch(CoreEvent::DEVMODE_DISABLED, new SystemEventPayload());
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
		} catch (\Throwable) {
			// The file was readable a moment ago and is not now — most often another
			// request unlinking it on expiry between file_exists() and the read. On
			// installs that promote warnings to exceptions this arrives as an
			// ErrorException, which used to escape this method entirely. Report
			// inactive, but do NOT disableDevMode(): the read failing tells us
			// nothing about the file's contents, and deleting it (plus dispatching
			// DEVMODE_DISABLED) would destroy state that is probably still valid.
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
		} catch (\Throwable) {
			// Invalid JSON, or the file vanished between the check and the read.
			return 0;
		}
	}

	/**
	 * Get development mode status information.
	 *
	 * isDevModeActive() above has already read the same file, so a failure here
	 * means it changed underneath us — another request enabling, disabling or
	 * expiring it between the two reads. Retrying once re-reads through
	 * isDevModeActive(), which both settles the question and cleans up a corrupt
	 * file; that is why this retries rather than returning the disabled array
	 * outright. The retry is capped at one: this used to call itself with no
	 * bound, and a writer alternating valid and invalid content in step with the
	 * reads drove it to a SIGSEGV rather than any catchable error.
	 *
	 * @param bool $retry false on the retry itself, so it cannot recurse further
	 *
	 * @return array<string,mixed>
	 */
	public function getDevModeStatus(bool $retry = true): array
	{
		if (!$this->isDevModeActive()) {
			return $this->disabledStatus();
		}

		try {
			$content = file_get_contents($this->devModeFile);
			if ($content === false) {
				return $retry ? $this->getDevModeStatus(false) : $this->disabledStatus();
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
		} catch (\Throwable) {
			// Not just \JsonException: on installs that promote warnings to
			// exceptions, a file unlinked between the two reads throws here
			// instead, and that used to escape getDevModeStatus() to the caller.
			return $retry ? $this->getDevModeStatus(false) : $this->disabledStatus();
		}
	}

	/**
	 * The status array for "dev mode is off", the shape callers rely on.
	 *
	 * @return array<string,mixed>
	 */
	private function disabledStatus(): array
	{
		return [
			'enabled'             => false,
			'remaining_seconds'   => 0,
			'remaining_formatted' => '0:00:00',
			'expires_at'          => null,
			'started_at'          => null,
		];
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
