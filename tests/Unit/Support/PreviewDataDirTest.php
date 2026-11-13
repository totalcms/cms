<?php

declare(strict_types=1);

use TotalCMS\Support\PreviewDataDir;

// A PHP without $TMPDIR answered /var/tmp from sys_get_temp_dir(), which the
// RapidWeaver sandbox refuses, and Stacks Preview rendered a blank page.

beforeEach(function () {
	$this->root = sys_get_temp_dir() . '/preview-datadir-' . uniqid();
	mkdir($this->root);
});

afterEach(function () {
	if (is_dir($this->root . '/locked')) {
		chmod($this->root . '/locked', 0755);
	}
	exec('rm -rf ' . escapeshellarg($this->root));
});

test('the first writable candidate is used', function () {
	$dir = PreviewDataDir::resolve($this->root . '/fallback', [$this->root . '/tmp/']);

	expect($dir)->toBe($this->root . '/tmp/' . PreviewDataDir::FOLDER)
		->and(is_dir($dir))->toBeTrue()
		->and(glob($dir . '/.write-test-*'))->toBe([]);
});

test('a candidate that refuses writes is skipped', function () {
	if (function_exists('posix_getuid') && posix_getuid() === 0) {
		$this->markTestSkipped('root writes through file permissions');
	}
	mkdir($this->root . '/locked', 0555);
	mkdir($this->root . '/open');

	$dir = PreviewDataDir::resolve($this->root . '/fallback', [
		$this->root . '/locked',
		$this->root . '/open',
	]);

	expect($dir)->toBe($this->root . '/open/' . PreviewDataDir::FOLDER);
});

test('missing candidates are skipped and the fallback is used when none can be written', function () {
	if (function_exists('posix_getuid') && posix_getuid() === 0) {
		$this->markTestSkipped('root writes through file permissions');
	}
	mkdir($this->root . '/locked', 0555);

	$dir = PreviewDataDir::resolve($this->root . '/tcms', [false, null, '', $this->root . '/locked']);

	expect($dir)->toBe($this->root . '/tcms/' . PreviewDataDir::FOLDER);
});

test('the system temp folder comes first, then TMPDIR', function () {
	$candidates = PreviewDataDir::candidates();

	expect($candidates[0])->toBe(sys_get_temp_dir())
		->and($candidates[1])->toBe(getenv('TMPDIR'));
});
