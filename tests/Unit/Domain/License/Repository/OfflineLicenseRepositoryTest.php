<?php

declare(strict_types=1);

use TotalCMS\Domain\License\Repository\OfflineLicenseRepository;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

// Where the offline licence file lives, and the operator convenience that makes
// it work: the file may be dropped in the root of tcms-data over FTP/SSH, and
// the repository quietly relocates it into .system on first access.

const OFFLINE_UPLOAD_PATH = 'example.test-offline-license.key';
const OFFLINE_SYSTEM_PATH = '.system/example.test-offline-license.key';

/**
 * @param array<string,string> $files  path => contents present in storage
 * @param array<int,array{0:string,1:string}> $moves records each move() call
 */
function offlineLicenseRepo(array $files, array &$moves = [], array &$deleted = []): OfflineLicenseRepository
{
	$storage = test()->createMock(StorageAdapterInterface::class);
	// By reference: move() relocates the file mid-call, and fileExists()/read()
	// must see that. An arrow fn captures by value and would not.
	$storage->method('fileExists')->willReturnCallback(
		function (string $path) use (&$files): bool {
			return array_key_exists($path, $files);
		}
	);
	$storage->method('read')->willReturnCallback(
		function (string $path) use (&$files): string {
			return $files[$path] ?? '';
		}
	);
	$storage->method('directoryExists')->willReturn(true);
	$storage->method('move')->willReturnCallback(
		function (string $from, string $to) use (&$files, &$moves): bool {
			$moves[]     = [$from, $to];
			$files[$to]  = $files[$from];
			unset($files[$from]);

			return true;
		}
	);
	$storage->method('delete')->willReturnCallback(
		function (string $path) use (&$deleted): bool {
			$deleted[] = $path;

			return true;
		}
	);

	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = 'example.test';

	return new OfflineLicenseRepository($storage, $config);
}

describe('OfflineLicenseRepository file location', function (): void {
	it('names the file after the domain so another site\'s licence cannot be used by accident', function (): void {
		expect(offlineLicenseRepo([])->getExpectedFilename())->toBe('example.test-offline-license.key');
	});

	it('points operators at the root of tcms-data for the drop', function (): void {
		expect(offlineLicenseRepo([])->getUploadDirectory())->toBe('');
	});
});

describe('OfflineLicenseRepository::read', function (): void {
	it('returns the trimmed token from the system location', function (): void {
		$repo = offlineLicenseRepo([OFFLINE_SYSTEM_PATH => "  a.token.here \n"]);

		expect($repo->read())->toBe('a.token.here');
	});

	it('returns null when no licence file exists anywhere', function (): void {
		expect(offlineLicenseRepo([])->read())->toBeNull();
	});

	it('treats a whitespace-only file as absent rather than as an empty token', function (): void {
		// An empty file would otherwise reach the JWT decoder as "".
		expect(offlineLicenseRepo([OFFLINE_SYSTEM_PATH => "   \n\t "])->read())->toBeNull();
	});
});

describe('OfflineLicenseRepository upload relocation', function (): void {
	it('moves a file dropped in the data root into .system before reading it', function (): void {
		// The operator convenience: upload to tcms-data/ over FTP and it lands
		// in the secure location on first access.
		$moves = [];
		$repo  = offlineLicenseRepo([OFFLINE_UPLOAD_PATH => 'dropped.token.value'], $moves);

		expect($repo->read())->toBe('dropped.token.value');
		expect($moves)->toBe([[OFFLINE_UPLOAD_PATH, OFFLINE_SYSTEM_PATH]]);
	});

	it('relocates on exists() too, not only on read()', function (): void {
		$moves = [];
		$repo  = offlineLicenseRepo([OFFLINE_UPLOAD_PATH => 'token'], $moves);

		expect($repo->exists())->toBeTrue();
		expect($moves)->toBe([[OFFLINE_UPLOAD_PATH, OFFLINE_SYSTEM_PATH]]);
	});

	it('does not move anything when the file is already in .system', function (): void {
		$moves = [];
		$repo  = offlineLicenseRepo([OFFLINE_SYSTEM_PATH => 'token'], $moves);

		expect($repo->exists())->toBeTrue();
		expect($moves)->toBe([]);
	});
});

describe('OfflineLicenseRepository::exists', function (): void {
	it('is false when nothing has been uploaded', function (): void {
		expect(offlineLicenseRepo([])->exists())->toBeFalse();
	});
});

describe('OfflineLicenseRepository::delete', function (): void {
	it('removes the file from the system location', function (): void {
		$moves   = [];
		$deleted = [];
		$repo    = offlineLicenseRepo([OFFLINE_SYSTEM_PATH => 'token'], $moves, $deleted);

		expect($repo->delete())->toBeTrue();
		expect($deleted)->toBe([OFFLINE_SYSTEM_PATH]);
	});

	it('succeeds without touching storage when there is nothing to delete', function (): void {
		// Idempotent: removing an absent licence is not an error.
		$moves   = [];
		$deleted = [];
		$repo    = offlineLicenseRepo([], $moves, $deleted);

		expect($repo->delete())->toBeTrue();
		expect($deleted)->toBe([]);
	});
});
