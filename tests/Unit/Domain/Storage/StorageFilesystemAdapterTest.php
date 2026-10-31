<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Local\LocalFilesystemAdapter;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/*
 * Writes replace a file by renaming a finished temp file over it, so a reader
 * sees the old contents or the new, never a half-written file. Before this,
 * two overlapping saves on a busy site read truncated .meta.json and
 * .index.json files ("Collection does not exist", "not valid JSON").
 */

beforeEach(function (): void {
	$this->dir = sys_get_temp_dir() . '/tcms-adapter-' . bin2hex(random_bytes(4));
	mkdir($this->dir . '/posts', 0755, true);
	$this->adapter = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->dir)), $this->dir);
});

afterEach(function (): void {
	@chmod($this->dir . '/posts', 0755);
	exec('/bin/rm -rf ' . escapeshellarg($this->dir));
});

test('write replaces the file instead of rewriting it in place', function (): void {
	$this->adapter->write('posts/a.json', '{"v":1}');
	$before = fileinode($this->dir . '/posts/a.json');
	clearstatcache();

	$this->adapter->write('posts/a.json', '{"v":2}');

	clearstatcache();
	expect(fileinode($this->dir . '/posts/a.json'))->not->toBe($before);
	expect(file_get_contents($this->dir . '/posts/a.json'))->toBe('{"v":2}');
});

test('write leaves no temp file behind', function (): void {
	$this->adapter->write('posts/a.json', '{"v":1}');
	$this->adapter->write('posts/a.json', '{"v":2}');

	expect(array_values(array_diff(scandir($this->dir . '/posts'), ['.', '..'])))->toBe(['a.json']);
});

test('a failed write keeps the old file and removes the temp', function (): void {
	if (function_exists('posix_getuid') && posix_getuid() === 0) {
		$this->markTestSkipped('root ignores directory permissions');
	}
	$this->adapter->write('posts/a.json', '{"v":1}');
	chmod($this->dir . '/posts', 0500);

	expect(fn () => $this->adapter->write('posts/a.json', '{"v":2}'))->toThrow(UnableToWriteFile::class);

	chmod($this->dir . '/posts', 0755);
	expect(file_get_contents($this->dir . '/posts/a.json'))->toBe('{"v":1}');
	expect(glob($this->dir . '/posts/.a.json.tmp.*'))->toBe([]);
});

test('write keeps an existing file mode', function (): void {
	$this->adapter->write('posts/a.json', '{"v":1}');
	chmod($this->dir . '/posts/a.json', 0640);

	$this->adapter->write('posts/a.json', '{"v":2}');

	clearstatcache();
	expect(fileperms($this->dir . '/posts/a.json') & 0777)->toBe(0640);
});

test('write creates a missing directory and file', function (): void {
	$this->adapter->write('fresh/dir/b.json', '{}');

	expect(file_get_contents($this->dir . '/fresh/dir/b.json'))->toBe('{}');
	expect(array_values(array_diff(scandir($this->dir . '/fresh/dir'), ['.', '..'])))->toBe(['b.json']);
});

test('a root-level file gets a dot-file temp in the root', function (): void {
	$this->adapter->write('top.json', '{"v":1}');
	$this->adapter->write('top.json', '{"v":2}');

	expect(file_get_contents($this->dir . '/top.json'))->toBe('{"v":2}');
	expect(glob($this->dir . '/.top.json.tmp.*'))->toBe([]);
});

test('writeStream replaces the file atomically', function (): void {
	$this->adapter->write('posts/.index.json', '{"objects":[]}');
	$before = fileinode($this->dir . '/posts/.index.json');
	clearstatcache();

	$stream = fopen('php://temp', 'w+');
	fwrite($stream, '{"objects":[{"id":"a"}]}');
	rewind($stream);
	$this->adapter->writeStream('posts/.index.json', $stream);
	fclose($stream);

	clearstatcache();
	expect(fileinode($this->dir . '/posts/.index.json'))->not->toBe($before);
	expect(file_get_contents($this->dir . '/posts/.index.json'))->toBe('{"objects":[{"id":"a"}]}');
	expect(array_values(array_diff(scandir($this->dir . '/posts'), ['.', '..'])))->toBe(['.index.json']);
});
