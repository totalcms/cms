<?php

declare(strict_types=1);

use TotalCMS\Domain\Storage\FileLock;

/*
 * An exclusive flock on a sidecar "<file>.lock", shared by everything that
 * reads, changes and writes back one file. Other processes are the point, so
 * the holder in these tests is a child PHP process.
 */

beforeEach(function (): void {
	$this->dir = sys_get_temp_dir() . '/tcms-lock-' . bin2hex(random_bytes(4));
	mkdir($this->dir . '/posts', 0755, true);
	$this->lock = new FileLock($this->dir);
});

afterEach(function (): void {
	exec('/bin/rm -rf ' . escapeshellarg($this->dir));
});

test('withLock returns what the callback returns', function (): void {
	expect($this->lock->withLock('posts/.index.json', fn () => 42))->toBe(42);
});

test('the lock file is a dot-file beside the file it guards', function (): void {
	$this->lock->withLock('posts/.index.json', fn () => null);

	expect(is_file($this->dir . '/posts/.index.json.lock'))->toBeTrue();
});

test('withLock waits while another process holds the lock', function (): void {
	[$proc, $pipes] = holdLockInChild($this->dir . '/posts/.index.json.lock', 1.0);

	$start = microtime(true);
	$this->lock->withLock('posts/.index.json', fn () => null);
	$waited = microtime(true) - $start;

	fclose($pipes[1]);
	proc_close($proc);
	expect($waited)->toBeGreaterThan(0.8);
});

test('withLock releases the lock when the callback throws', function (): void {
	expect(fn () => $this->lock->withLock('posts/.index.json', function (): never {
		throw new RuntimeException('boom');
	}))->toThrow(RuntimeException::class, 'boom');

	$code = sprintf('$h = fopen(%s, "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "free" : "held";', var_export($this->dir . '/posts/.index.json.lock', true));
	expect(shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)))->toBe('free');
});
