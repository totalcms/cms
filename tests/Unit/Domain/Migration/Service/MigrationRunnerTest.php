<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Migration\Repository\MigrationStateRepository;
use TotalCMS\Domain\Migration\Service\MigrationRunner;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Build a real MigrationStateRepository over a mocked storage adapter. Lets
 * tests express "already ran" by seeding the ledger via $storage->read() and
 * verify "ran successfully" by inspecting the written JSON.
 *
 * @param array<string,array{ranAt:string,result:int}> $existingLedger
 */
function makeStateRepo(array $existingLedger, ?array &$writtenLedger = null): MigrationStateRepository
{
	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn($existingLedger !== []);
	$storage->method('read')->willReturn((string)json_encode($existingLedger));
	$storage->method('write')->willReturnCallback(function (string $path, string $json) use (&$writtenLedger): bool {
		$decoded = json_decode($json, true);
		if (is_array($decoded)) {
			$writtenLedger = $decoded;
		}

		return true;
	});
	$storage->method('move')->willReturn(true);

	return new MigrationStateRepository($storage);
}

/**
 * Build a one-off migration whose run() behavior is controlled by the caller.
 *
 * @param callable():int $runBehavior
 */
function fakeMigration(string $id, callable $runBehavior): MigrationInterface
{
	return new class($id, $runBehavior) implements MigrationInterface {
		public int $runCount = 0;

		public function __construct(
			private readonly string $id,
			private readonly mixed $runBehavior,
		) {
		}

		public function id(): string
		{
			return $this->id;
		}

		public function description(): string
		{
			return 'fake migration ' . $this->id;
		}

		public function run(): int
		{
			$this->runCount++;

			return ($this->runBehavior)();
		}
	};
}

describe('MigrationRunner', function (): void {
	test('runs migrations that are not in the ledger', function (): void {
		$written = null;
		$state   = makeStateRepo([], $written);

		$migration = fakeMigration('m1', fn (): int => 7);

		(new MigrationRunner([$migration], $state, new NullLogger()))->runPending();

		expect($migration->runCount)->toBe(1);
		expect($written)->toHaveKey('m1');
		expect($written['m1']['result'])->toBe(7);
	});

	test('skips migrations that are already in the ledger', function (): void {
		$written = null;
		$state   = makeStateRepo(
			['m1' => ['ranAt' => '2026-05-18T12:00:00Z', 'result' => 7]],
			$written,
		);

		$migration = fakeMigration('m1', fn (): int => 7);

		(new MigrationRunner([$migration], $state, new NullLogger()))->runPending();

		expect($migration->runCount)->toBe(0);
		expect($written)->toBeNull();
	});

	test('failed migrations are not recorded so they retry next process', function (): void {
		$written = null;
		$state   = makeStateRepo([], $written);

		$broken = fakeMigration('broken', function (): int {
			throw new RuntimeException('disk full');
		});

		// Failure should be swallowed (logged) — runPending() returns normally.
		(new MigrationRunner([$broken], $state, new NullLogger()))->runPending();

		expect($broken->runCount)->toBe(1);
		expect($written)->toBeNull();
	});

	test('one failed migration does not block subsequent migrations', function (): void {
		$written = null;
		$state   = makeStateRepo([], $written);

		$broken  = fakeMigration('broken', function (): int {
			throw new RuntimeException('disk full');
		});
		$healthy = fakeMigration('healthy', fn (): int => 3);

		(new MigrationRunner([$broken, $healthy], $state, new NullLogger()))->runPending();

		expect($broken->runCount)->toBe(1);
		expect($healthy->runCount)->toBe(1);
		expect($written)->toHaveKey('healthy');
		expect($written)->not->toHaveKey('broken');
	});

	test('a no-op migration (result 0) is still recorded so it does not re-run', function (): void {
		$written = null;
		$state   = makeStateRepo([], $written);

		$noop = fakeMigration('noop', fn (): int => 0);

		(new MigrationRunner([$noop], $state, new NullLogger()))->runPending();

		expect($written)->toHaveKey('noop');
		expect($written['noop']['result'])->toBe(0);
	});
});
