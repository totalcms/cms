<?php

declare(strict_types=1);

namespace Tests\Fakes;

/**
 * Stands in for the other process in DevModeManager's read-read race.
 *
 * DevModeManager reads its state file twice per getDevModeStatus() call: once
 * through isDevModeActive(), then again for the payload. Everything interesting
 * lives in the gap between those two reads, which only a concurrent writer can
 * open. Config::systemDir() just concatenates onto datadir, so pointing datadir
 * at this wrapper's scheme puts a test in control of each individual read.
 *
 * A class of its own rather than one declared inside the test file: php-cs-fixer
 * renames a class in a *Test.php to match the filename, and a class declared
 * there is not reliably visible to its own closures under --parallel.
 */
final class DevModeRaceStreamWrapper
{
	/** @var resource|null */
	public $context;

	public static int $opens   = 0;
	public static int $unlinks = 0;

	/** How many of the payload reads serve corrupt content. */
	public static int $corruptPayloadReads = 0;

	/** Every read serves corrupt content. */
	public static bool $alwaysCorrupt = false;

	/** The payload read fails outright, as if the file had just been removed. */
	public static bool $vanishOnSecondRead = false;

	/** The isDevModeActive() read fails outright, before any payload read. */
	public static bool $vanishOnFirstRead = false;

	private string $buffer = '';
	private int $position  = 0;

	public static function reset(): void
	{
		self::$opens               = 0;
		self::$unlinks             = 0;
		self::$corruptPayloadReads = 0;
		self::$alwaysCorrupt       = false;
		self::$vanishOnSecondRead  = false;
		self::$vanishOnFirstRead   = false;
	}

	private static function activeState(): string
	{
		return (string)json_encode([
			'enabled'    => true,
			'expires_at' => time() + 10800,
			'started_at' => time(),
		]);
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
	{
		self::$opens++;

		// Odd reads are isDevModeActive(); even reads are the payload read that
		// follows it. Only the even ones can be made to differ from what
		// isDevModeActive() just saw.
		$isPayloadRead = self::$opens % 2 === 0;

		if ((!$isPayloadRead && self::$vanishOnFirstRead) || ($isPayloadRead && self::$vanishOnSecondRead)) {
			trigger_error('failed to open stream: No such file or directory', E_USER_WARNING);

			return false;
		}

		$corrupt = self::$alwaysCorrupt
			|| ($isPayloadRead && self::$opens / 2 <= self::$corruptPayloadReads);

		$this->buffer   = $corrupt ? '{ this is not json' : self::activeState();
		$this->position = 0;

		return true;
	}

	public function stream_read(int $count): string
	{
		$chunk = substr($this->buffer, $this->position, $count);
		$this->position += strlen($chunk);

		return $chunk;
	}

	public function stream_eof(): bool
	{
		return $this->position >= strlen($this->buffer);
	}

	/** @return array<int|string,int> */
	public function stream_stat(): array
	{
		return ['size' => strlen($this->buffer)];
	}

	public function stream_close(): void
	{
	}

	/** @return array<int|string,int> */
	public function url_stat(string $path, int $flags): array
	{
		return ['mode' => str_ends_with($path, '.json') ? 0100644 : 0040755, 'size' => 1];
	}

	public function unlink(string $path): bool
	{
		self::$unlinks++;

		return true;
	}

	public function mkdir(string $path, int $mode, int $options): bool
	{
		return true;
	}
}
