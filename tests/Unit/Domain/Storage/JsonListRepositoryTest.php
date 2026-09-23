<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\JsonListRepository;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

// JsonListRepository is "a JSON file holding a list of records under one key",
// built on AtomicJsonStore so every such file gets the same locked, atomic,
// corrupt-aware write. The two OAuth repositories each hand-rolled this
// (find / save-or-append / delete / read-all / temp-file-then-rename), with no
// lock and no corrupt-file policy, for the file that holds refresh-token hashes.
final class JsonListRepositoryTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/totalcms-jsonlist-' . uniqid();
		mkdir($this->dir, 0700, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($this->dir);
	}

	private function repository(): JsonListRepository
	{
		$store = new AtomicJsonStore(new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->dir))), $this->dir, new NullLogger());

		return new class($store, 'things.json') extends JsonListRepository {
			protected function rootKey(): string
			{
				return 'things';
			}

			/** @return list<array<string,mixed>> */
			public function rows(): array
			{
				return $this->entries();
			}

			/** @param array<string,mixed> $row */
			public function put(array $row): void
			{
				$this->upsert($row);
			}

			public function drop(string $id): void
			{
				$this->removeWhere(static fn (array $row): bool => ($row['id'] ?? null) !== $id);
			}

			public function pruneEven(): int
			{
				return $this->removeWhere(static fn (array $row): bool => ($row['n'] ?? 0) % 2 !== 0);
			}
		};
	}

	public function testAMissingFileIsAnEmptyList(): void
	{
		$this->assertSame([], $this->repository()->rows());
	}

	public function testUpsertAppendsNewIdsAndReplacesExistingOnes(): void
	{
		$repo = $this->repository();
		$repo->put(['id' => 'a', 'n' => 1]);
		$repo->put(['id' => 'b', 'n' => 2]);
		$repo->put(['id' => 'a', 'n' => 3]);

		$this->assertSame([['id' => 'a', 'n' => 3], ['id' => 'b', 'n' => 2]], $repo->rows());
		$this->assertSame(['things' => [['id' => 'a', 'n' => 3], ['id' => 'b', 'n' => 2]]], json_decode((string)file_get_contents($this->dir . '/things.json'), true), 'the on-disk shape is the root key holding the list');
	}

	public function testRemoveWhereKeepsWhatTheCallbackAcceptsAndReportsTheRest(): void
	{
		$repo = $this->repository();
		foreach ([1, 2, 3, 4] as $n) {
			$repo->put(['id' => "r{$n}", 'n' => $n]);
		}

		$this->assertSame(2, $repo->pruneEven());
		$this->assertSame(['r1', 'r3'], array_column($repo->rows(), 'id'));

		$repo->drop('r1');
		$this->assertSame(['r3'], array_column($repo->rows(), 'id'));
	}

	public function testRemovingNothingDoesNotTouchTheFile(): void
	{
		$repo = $this->repository();
		$repo->put(['id' => 'a', 'n' => 1]);
		$before = filemtime($this->dir . '/things.json');
		touch($this->dir . '/things.json', $before - 100);

		$this->assertSame(0, $repo->pruneEven());
		$this->assertSame($before - 100, filemtime($this->dir . '/things.json'));
	}

	public function testTheFileIsWrittenPrivate(): void
	{
		$repo = $this->repository();
		$repo->put(['id' => 'a']);

		$this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/things.json')), -4));
	}

	public function testACorruptFileIsRefusedNotSilentlyReplaced(): void
	{
		file_put_contents($this->dir . '/things.json', '{not json');

		$this->expectException(\RuntimeException::class);
		$this->repository()->rows();
	}
}
