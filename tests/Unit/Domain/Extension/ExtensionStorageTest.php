<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use TotalCMS\Domain\Extension\ExtensionStorage;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

const EXT_STORAGE_BASE = '.system/extension-data/test-vendor/storage-ext';

beforeEach(function (): void {
	$this->datadir = sys_get_temp_dir() . '/tcms-ext-storage-' . uniqid();
	mkdir($this->datadir, 0755, true);

	$adapter       = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->datadir)));
	$this->storage = new ExtensionStorage($adapter, $this->datadir, EXT_STORAGE_BASE);
});

afterEach(function (): void {
	if (is_dir($this->datadir)) {
		recursiveDelete($this->datadir);
	}
});

describe('ExtensionStorage', function (): void {
	test('write creates directories and read round-trips', function (): void {
		$this->storage->write('secret', 'abc123');

		expect($this->storage->read('secret'))->toBe('abc123')
			->and($this->storage->exists('secret'))->toBeTrue()
			->and(is_file($this->datadir . '/' . EXT_STORAGE_BASE . '/secret'))->toBeTrue();
	});

	test('write supports nested relative paths', function (): void {
		$this->storage->write('cache/feeds/latest.json', '{"a":1}');

		expect($this->storage->read('cache/feeds/latest.json'))->toBe('{"a":1}');
	});

	test('files are written with private visibility (0600)', function (): void {
		$this->storage->write('secret', 'abc123');

		expect(substr(sprintf('%o', fileperms($this->storage->path('secret'))), -4))->toBe('0600');
	});

	test('read returns null for missing files', function (): void {
		expect($this->storage->read('nope'))->toBeNull()
			->and($this->storage->exists('nope'))->toBeFalse();
	});

	test('delete removes files and tolerates missing ones', function (): void {
		$this->storage->write('temp', 'x');

		expect($this->storage->delete('temp'))->toBeTrue()
			->and($this->storage->exists('temp'))->toBeFalse()
			->and($this->storage->delete('temp'))->toBeTrue();
	});

	test('path returns absolute filesystem paths', function (): void {
		expect($this->storage->path())->toBe($this->datadir . '/' . EXT_STORAGE_BASE)
			->and($this->storage->path('secret'))->toBe($this->datadir . '/' . EXT_STORAGE_BASE . '/secret');
	});

	test('rejects absolute paths', function (): void {
		$this->storage->write('/etc/evil', 'x');
	})->throws(InvalidArgumentException::class);

	test('rejects directory traversal', function (): void {
		$this->storage->write('../outside', 'x');
	})->throws(InvalidArgumentException::class);

	test('rejects traversal hidden mid-path', function (): void {
		$this->storage->read('cache/../../outside');
	})->throws(InvalidArgumentException::class);

	test('rejects backslash traversal', function (): void {
		$this->storage->write('..\\outside', 'x');
	})->throws(InvalidArgumentException::class);

	test('traversal cannot reach sibling datadir files Flysystem would allow', function (): void {
		// Flysystem only guards the DATADIR root; this path stays inside it,
		// so only the per-extension guard stands between extensions and other
		// extensions' secrets.
		$this->storage->read('../../other-vendor/other-ext/secret');
	})->throws(InvalidArgumentException::class);

	test('allows dotfiles and double-dot filenames that are not traversal', function (): void {
		$this->storage->write('.state', 'a');
		$this->storage->write('file..name', 'b');

		expect($this->storage->read('.state'))->toBe('a')
			->and($this->storage->read('file..name'))->toBe('b');
	});

	test('write throws when the storage directory cannot be created', function (): void {
		// A FILE blocking the .system path makes directory creation fail.
		// PHPUnit records Flysystem's (expected, @-suppressed) mkdir warning
		// unless we pre-empt its error handler for the duration of the call.
		mkdir($this->datadir . '/.system', 0700);
		file_put_contents($this->datadir . '/.system/extension-data', 'blocker');
		set_error_handler(static fn (): bool => true);

		try {
			expect(fn () => $this->storage->write('secret', 'x'))
				->toThrow(League\Flysystem\UnableToCreateDirectory::class);
		} finally {
			restore_error_handler();
		}
	});
});
