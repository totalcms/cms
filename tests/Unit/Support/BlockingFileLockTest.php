<?php

declare(strict_types=1);

use TotalCMS\Support\BlockingFileLock;

beforeEach(function (): void {
	$this->lockPath = sys_get_temp_dir() . '/tcms-lock-' . uniqid() . '/a.lock';
});

afterEach(function (): void {
	/** @var string $dir */
	$dir = dirname($this->lockPath);
	/** @phpstan-ignore-next-line */
	exec('rm -rf ' . escapeshellarg($dir));
});

test('run returns the callback result', function (): void {
	expect(BlockingFileLock::run($this->lockPath, fn () => 42))->toBe(42);
});

test('run releases the lock when the callback throws', function (): void {
	expect(fn () => BlockingFileLock::run($this->lockPath, function (): void {
		throw new RuntimeException('boom');
	}))->toThrow(RuntimeException::class, 'boom');

	// A second blocking run would hang forever if the lock were still held.
	expect(BlockingFileLock::run($this->lockPath, fn () => 'ok'))->toBe('ok');

	$handle = fopen($this->lockPath, 'c');
	expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
	fclose($handle);
});

test('run holds an exclusive lock while the callback runs', function (): void {
	$held = BlockingFileLock::run($this->lockPath, function (): bool {
		$other = fopen($this->lockPath, 'c');
		$got   = flock($other, LOCK_EX | LOCK_NB);
		fclose($other);

		return $got;
	});

	expect($held)->toBeFalse();
});

test('run still runs the callback when the lock file cannot be opened', function (): void {
	$blocked = sys_get_temp_dir() . '/tcms-lock-file-' . uniqid();
	file_put_contents($blocked, 'x');

	expect(BlockingFileLock::run($blocked . '/nope.lock', fn () => 'ran'))->toBe('ran');

	unlink($blocked);
});
