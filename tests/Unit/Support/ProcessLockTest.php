<?php

declare(strict_types=1);

use TotalCMS\Support\ProcessLock;

// The single-flight flock the four cron entry points used to each spell out.
beforeEach(function (): void {
	$this->path = sys_get_temp_dir() . '/tcms-lock-' . uniqid() . '.lock';
});

afterEach(function (): void {
	@unlink($this->path);
});

test('a second holder cannot take the lock until the first releases it', function (): void {
	$first = ProcessLock::open($this->path);
	expect($first)->not->toBeNull()->and($first->acquire())->toBeTrue();

	$second = ProcessLock::open($this->path);
	expect($second->acquire())->toBeFalse();

	$first->release();
	expect(ProcessLock::open($this->path)->acquire())->toBeTrue();
});

test('the pid stamp and the unlink-on-release are opt-in', function (): void {
	$lock = ProcessLock::open($this->path);
	$lock->acquire();
	$lock->stampPid();

	expect((int)file_get_contents($this->path))->toBe(getmypid());

	$lock->release(unlink: true);
	expect(file_exists($this->path))->toBeFalse();
});

test('an unopenable lock file is null rather than a lock that never contends', function (): void {
	expect(ProcessLock::open('/nonexistent-dir-' . uniqid() . '/x.lock'))->toBeNull();
});
