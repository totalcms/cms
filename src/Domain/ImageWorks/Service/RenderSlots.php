<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Exception\RenderBusyException;
use TotalCMS\Support\Config;
use TotalCMS\Support\QuietCall;

/**
 * Caps how many ImageWorks renders run at once. A render is mostly CPU, so
 * past a couple per core more of them only queue for the CPU while holding
 * a PHP-FPM worker each, and a burst of misses starves page requests too.
 * The default is two per core: part of each render is file reading and
 * writing, which a second render can overlap.
 *
 * Each slot is an flock on `.system/imageworks-slots/slot-N.lock`; the OS
 * drops the lock when the process ends, however it ends. A render that
 * cannot get a slot within `renderWait` seconds throws RenderBusyException,
 * which the public routes turn into a 503 with Retry-After. The wait is
 * long (30 s) for real visitors: a gallery's first view asks for every
 * thumbnail at once, a browser does not retry an image that failed, and a
 * waiting request only sleeps in short polls, so it costs no CPU.
 *
 * Only cache misses come here — a cached file is served without a slot. When
 * the lock files cannot be opened at all, renders go ahead unlimited: a
 * permissions problem must not take every image on the site down.
 */
final class RenderSlots
{
	private const POLL_MICROSECONDS = 50_000;
	private const FALLBACK_CPUS     = 2;
	private const SLOTS_PER_CPU     = 2;
	private const DEFAULT_WAIT      = 30;

	private static ?int $cpus = null;

	public function __construct(
		private readonly string $dir,
		private readonly int $slots,
		private readonly float $wait,
	) {
	}

	public static function fromConfig(Config $config): self
	{
		$slots = (int)($config->imageworks['renderSlots'] ?? 0);

		return new self(
			$config->systemDir() . '/imageworks-slots',
			$slots > 0 ? $slots : self::cpuCount() * self::SLOTS_PER_CPU,
			max(0.0, (float)($config->imageworks['renderWait'] ?? self::DEFAULT_WAIT)),
		);
	}

	/**
	 * Run $render while holding a slot.
	 *
	 * @template T
	 *
	 * @param callable(): T $render
	 *
	 * @throws RenderBusyException
	 *
	 * @return T
	 */
	public function run(callable $render): mixed
	{
		$handle = $this->acquire();

		if ($handle === false) {
			throw new RenderBusyException(max(1, (int)ceil($this->wait)));
		}

		try {
			return $render();
		} finally {
			if (is_resource($handle)) {
				flock($handle, LOCK_UN);
				fclose($handle);
			}
		}
	}

	/**
	 * A locked slot handle; null when no slot file can be opened (run
	 * unlimited); false when every slot stayed busy for the whole wait.
	 *
	 * @return resource|false|null
	 */
	private function acquire()
	{
		if (!is_dir($this->dir) && !QuietCall::run(fn (): bool => mkdir($this->dir, 0755, true)) && !is_dir($this->dir)) {
			return null;
		}

		$deadline = microtime(true) + $this->wait;

		do {
			$opened = 0;
			for ($slot = 0; $slot < $this->slots; $slot++) {
				$path   = sprintf('%s/slot-%d.lock', $this->dir, $slot);
				$handle = QuietCall::run(fn () => fopen($path, 'c'));
				if ($handle === false) {
					continue;
				}

				$opened++;
				if (flock($handle, LOCK_EX | LOCK_NB)) {
					return $handle;
				}

				fclose($handle);
			}

			if ($opened === 0) {
				return null;
			}

			usleep(self::POLL_MICROSECONDS);
		} while (microtime(true) < $deadline);

		return false;
	}

	/** Cores from /proc/cpuinfo on Linux; 2 where it cannot be read. */
	private static function cpuCount(): int
	{
		if (self::$cpus !== null) {
			return self::$cpus;
		}

		// Quiet: open_basedir warns on the is_readable() itself.
		$cpuinfo = QuietCall::run(fn (): string|false => is_readable('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : false);
		$count   = is_string($cpuinfo) ? preg_match_all('/^processor\s*:/m', $cpuinfo) : 0;

		return self::$cpus = $count > 0 ? $count : self::FALLBACK_CPUS;
	}
}
