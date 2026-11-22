<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage;

/**
 * Numbers people read, such as order numbers: each call the next one, never
 * the same one twice.
 *
 * `next('orders', 1001)` gives 1001 the first time and counts up from there.
 * The start can be raised later (the count jumps ahead) but lowering it does
 * nothing: a number that was handed out is never handed out again.
 *
 * Every name is one entry in `.system/sequences.json`, changed under the
 * store's lock so two requests cannot take the same number. A file that
 * cannot be read stops the count: starting again from nothing would reuse
 * numbers.
 *
 * A number is taken when it is asked for. If what it was for is then
 * abandoned, the number is skipped; a sequence has gaps and no duplicates.
 */
final readonly class Sequence
{
	private const FILE = '.system/sequences.json';

	public function __construct(private AtomicJsonStore $store)
	{
	}

	/**
	 * Take the next number.
	 *
	 * @param string $name  which sequence: lowercase letters, digits and hyphens
	 * @param int    $start the lowest number this call may return
	 *
	 * @throws \InvalidArgumentException when the name is not a plain slug
	 * @throws \RuntimeException         when the sequence file cannot be read or written
	 */
	public function next(string $name, int $start = 1): int
	{
		$this->assertName($name);

		$next  = null;
		$saved = $this->store->mutate(self::FILE, function (array $data) use ($name, $start, &$next): array {
			$next        = max($this->stored($data, $name) + 1, $start);
			$data[$name] = $next;

			return $data;
		}, CorruptPolicy::Throw, lock: true);

		if (!$saved || !is_int($next)) {
			throw new \RuntimeException("The '{$name}' sequence could not be saved");
		}

		return $next;
	}

	/**
	 * The last number taken, or null when none has been.
	 *
	 * @throws \InvalidArgumentException when the name is not a plain slug
	 */
	public function current(string $name): ?int
	{
		$this->assertName($name);

		$data = $this->store->load(self::FILE, CorruptPolicy::Throw);

		return isset($data[$name]) ? $this->stored($data, $name) : null;
	}

	/** @param array<string,mixed> $data */
	private function stored(array $data, string $name): int
	{
		return is_int($data[$name] ?? null) ? $data[$name] : 0;
	}

	private function assertName(string $name): void
	{
		if (preg_match('/^[a-z0-9][a-z0-9-]*\z/', $name) !== 1) {
			throw new \InvalidArgumentException("'{$name}' is not a sequence name: lowercase letters, digits and hyphens");
		}
	}
}
