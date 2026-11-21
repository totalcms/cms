<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Serving;

use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Download counts, one small file per object:
 * `.system/extension-data/totalcms/s3-uploads/counts/{collection}/{id}.json`
 * holding `{"brochure": 12, "files/9f2c1a7e": 3, "@total": {"brochure": 40, "files": 9}}`.
 *
 * Two counts are kept. The count of a file belongs to that file and goes when
 * the file is replaced or removed. The total under `@total` belongs to the
 * property and only ever grows: every download of any file the property has
 * held. It cannot be rebuilt later, which is why it is recorded from the start.
 *
 * A download never writes the object itself, so it never rebuilds an index
 * or fires an object event.
 */
final readonly class DownloadCounter
{
	private const DIR = '.system/extension-data/totalcms/s3-uploads/counts/';

	/** Where the all-time totals sit in the file. No property can be named this, and it is not a number, so the per-file readers skip it. */
	private const TOTALS = '@total';

	public function __construct(
		private AtomicJsonStore $store,
		private StorageAdapterInterface $filesystem,
	) {
	}

	/** @param string $counterKey the property, or `{property}/{fileId}` for one file of a depot */
	public function increment(string $collection, string $id, string $counterKey): void
	{
		$path = $this->path($collection, $id);
		if ($path === null || $counterKey === '') {
			return;
		}

		try {
			$this->store->mutate($path, function (array $counts) use ($counterKey): array {
				$property = explode('/', $counterKey, 2)[0];
				$totals   = is_array($counts[self::TOTALS] ?? null) ? $counts[self::TOTALS] : [];

				$totals[$property]    = self::totalOf($counts, $property) + 1;
				$counts[$counterKey]  = (is_int($counts[$counterKey] ?? null) ? $counts[$counterKey] : 0) + 1;
				$counts[self::TOTALS] = $totals;

				return $counts;
			}, CorruptPolicy::RefuseWrites, lock: true);
		} catch (\Throwable) {
			// A count that cannot be written must not fail the download.
		}
	}

	public function count(string $collection, string $id, string $counterKey): int
	{
		return $this->forObject($collection, $id)[$counterKey] ?? 0;
	}

	/** @return array<string,int> counts keyed by counter key */
	public function forObject(string $collection, string $id): array
	{
		$path = $this->path($collection, $id);
		if ($path === null) {
			return [];
		}

		try {
			$stored = $this->store->load($path, CorruptPolicy::RefuseWrites);
		} catch (\Throwable) {
			return [];
		}

		$counts = [];
		foreach ($stored as $key => $count) {
			if (is_int($count)) {
				$counts[(string)$key] = $count;
			}
		}

		return $counts;
	}

	/** Every download the property has ever had, across all the files it has held. */
	public function total(string $collection, string $id, string $property): int
	{
		return $this->totalsForObject($collection, $id)[$property] ?? 0;
	}

	/** @return array<string,int> all-time totals keyed by property */
	public function totalsForObject(string $collection, string $id): array
	{
		$path = $this->path($collection, $id);
		if ($path === null) {
			return [];
		}

		try {
			$stored = $this->store->load($path, CorruptPolicy::RefuseWrites);
		} catch (\Throwable) {
			return [];
		}

		$properties = array_keys(is_array($stored[self::TOTALS] ?? null) ? $stored[self::TOTALS] : []);
		foreach ($stored as $key => $count) {
			if (is_int($count)) {
				$properties[] = explode('/', (string)$key, 2)[0];
			}
		}

		$totals = [];
		foreach (array_unique(array_map(strval(...), $properties)) as $property) {
			$totals[$property] = self::totalOf($stored, $property);
		}
		ksort($totals);

		return $totals;
	}

	/**
	 * Every stored all-time total, for reports. Unlike all(), this includes an
	 * object whose files are all gone: its history is still worth showing.
	 *
	 * @param string|null $collection only this collection
	 *
	 * @return array<string,array<string,array<string,int>>> collection => object id => property => total
	 */
	public function allTotals(?string $collection = null): array
	{
		$all = [];
		foreach ($this->objects($collection) as [$name, $id]) {
			$totals = $this->totalsForObject($name, $id);
			if ($totals !== []) {
				$all[$name][$id] = $totals;
			}
		}

		return $all;
	}

	/**
	 * Every stored count, for reports.
	 *
	 * @param string|null $collection only this collection
	 *
	 * @return array<string,array<string,array<string,int>>> collection => object id => counter key => count
	 */
	public function all(?string $collection = null): array
	{
		$all = [];
		foreach ($this->objects($collection) as [$name, $id]) {
			$counts = $this->forObject($name, $id);
			if ($counts !== []) {
				$all[$name][$id] = $counts;
			}
		}

		return $all;
	}

	/**
	 * Every object that has a count file, in a stable order.
	 *
	 * @return list<array{string, string}> [collection, object id]
	 */
	private function objects(?string $collection): array
	{
		try {
			$collections = $collection !== null ? [$collection] : array_map(basename(...), $this->filesystem->listDirectories(rtrim(self::DIR, '/')));
		} catch (\Throwable) {
			return [];
		}
		sort($collections);

		$objects = [];
		foreach ($collections as $name) {
			if ($this->path($name, 'x') === null) {
				continue;
			}

			try {
				$files = $this->filesystem->listFiles(self::DIR . $name);
			} catch (\Throwable) {
				continue;
			}
			sort($files);

			foreach ($files as $file) {
				// The folder also holds the store's ".lock" files.
				if (str_ends_with($file, '.json')) {
					$objects[] = [$name, basename($file, '.json')];
				}
			}
		}

		return $objects;
	}

	/**
	 * A property's all-time total in one stored file. Never less than what its
	 * files show now, so a file written before totals were kept starts from
	 * the counts it has.
	 *
	 * @param array<array-key,mixed> $stored
	 */
	private static function totalOf(array $stored, string $property): int
	{
		$current = 0;
		foreach ($stored as $key => $count) {
			if (is_int($count) && ((string)$key === $property || str_starts_with((string)$key, $property . '/'))) {
				$current += $count;
			}
		}

		$recorded = is_array($stored[self::TOTALS] ?? null) ? ($stored[self::TOTALS][$property] ?? 0) : 0;

		return max($current, is_int($recorded) ? $recorded : 0);
	}

	/**
	 * Drop the counts of files that are gone: a count belongs to the stored
	 * file, so a replaced or removed file takes its count with it. The property's
	 * all-time total stays.
	 *
	 * @param list<string> $counterKeys as increment() takes them
	 */
	public function drop(string $collection, string $id, array $counterKeys): void
	{
		$path = $this->path($collection, $id);

		try {
			// Nothing counted yet: do not create a file just to hold nothing.
			if ($path === null || $counterKeys === [] || !$this->filesystem->fileExists($path)) {
				return;
			}

			$this->store->mutate($path, fn (array $counts): array => array_diff_key($counts, array_flip($counterKeys)), CorruptPolicy::RefuseWrites, lock: true);
		} catch (\Throwable) {
			// A stale count is harmless.
		}
	}

	/** Drop every count and total for an object that no longer exists. */
	public function forget(string $collection, string $id): void
	{
		$path = $this->path($collection, $id);

		try {
			if ($path !== null && $this->filesystem->fileExists($path)) {
				$this->filesystem->delete($path);
			}
		} catch (\Throwable) {
			// A leftover count file is harmless.
		}
	}

	/** Null unless both parts are plain names: they become a file path. */
	private function path(string $collection, string $id): ?string
	{
		foreach ([$collection, $id] as $part) {
			if (preg_match('/^[A-Za-z0-9._-]+\z/', $part) !== 1 || trim($part, '.') === '') {
				return null;
			}
		}

		return self::DIR . $collection . '/' . $id . '.json';
	}
}
