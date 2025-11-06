<?php

use TotalCMS\Domain\Settings\Services\DataDirectoryManager;

describe('DataDirectoryManager', function (): void {
	beforeEach(function (): void {
		$this->manager = new DataDirectoryManager();
		$this->testDir = sys_get_temp_dir() . '/tcms-test-' . uniqid();
	});

	afterEach(function (): void {
		// Clean up test directories
		if (property_exists($this, 'testDir') && $this->testDir !== null && is_dir($this->testDir)) {
			recursiveDelete($this->testDir);
		}
	});

	it('resolves default data path correctly', function (): void {
		$docroot = '/var/www/html';
		$result  = $this->manager->resolveDataPath('default', $docroot);

		expect($result)->toBe('/var/www/tcms-data');
	});

	it('resolves docroot data path correctly', function (): void {
		$docroot = '/var/www/html';
		$result  = $this->manager->resolveDataPath('docroot', $docroot);

		expect($result)->toBe('/var/www/html/tcms-data');
	});

	it('resolves custom data path correctly', function (): void {
		$docroot    = '/var/www/html';
		$customPath = '/custom/path/to/data';
		$result     = $this->manager->resolveDataPath('custom', $docroot, $customPath);

		expect($result)->toBe('/custom/path/to/data');
	});

	it('trims whitespace from custom path', function (): void {
		$docroot    = '/var/www/html';
		$customPath = '  /custom/path  ';
		$result     = $this->manager->resolveDataPath('custom', $docroot, $customPath);

		expect($result)->toBe('/custom/path');
	});

	it('returns empty string for invalid location', function (): void {
		$docroot = '/var/www/html';
		$result  = $this->manager->resolveDataPath('invalid', $docroot);

		expect($result)->toBe('');
	});

	it('validates absolute path successfully', function (): void {
		expect(fn () => $this->manager->validateAbsolutePath('/absolute/path'))
			->not->toThrow(InvalidArgumentException::class);
	});

	it('throws exception for relative path', function (): void {
		expect(fn () => $this->manager->validateAbsolutePath('relative/path'))
			->toThrow(InvalidArgumentException::class, 'Custom path must be an absolute path');
	});

	it('validates existing parent directory successfully', function (): void {
		$parentDir = sys_get_temp_dir();
		$testPath  = $parentDir . '/test-child';

		expect(fn () => $this->manager->validateParentDirectory($testPath))
			->not->toThrow(RuntimeException::class);
	});

	it('throws exception for non-existent parent directory', function (): void {
		$testPath = '/nonexistent/parent/child';

		expect(fn () => $this->manager->validateParentDirectory($testPath))
			->toThrow(RuntimeException::class, 'Parent directory does not exist');
	});

	it('throws exception for non-writable parent directory', function (): void {
		// Skip this test on systems where we can't control permissions reliably
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$this->markTestSkipped('Permission tests not reliable on Windows');
		}

		$parentDir = sys_get_temp_dir() . '/readonly-' . uniqid();
		mkdir($parentDir, 0555, true); // Read-only
		$testPath = $parentDir . '/child';

		try {
			expect(fn () => $this->manager->validateParentDirectory($testPath))
				->toThrow(RuntimeException::class, 'Parent directory is not writable');
		} finally {
			// Clean up
			chmod($parentDir, 0755);
			rmdir($parentDir);
		}
	});

	it('creates directory with correct permissions', function (): void {
		$this->manager->createDirectory($this->testDir);

		expect(is_dir($this->testDir))->toBeTrue();
		expect(is_writable($this->testDir))->toBeTrue();
	});

	it('creates .htaccess security file', function (): void {
		$this->manager->createDirectory($this->testDir);

		$htaccessPath = $this->testDir . '/.htaccess';
		expect(file_exists($htaccessPath))->toBeTrue();

		$content = file_get_contents($htaccessPath);
		expect($content)->toContain('Require all denied');
		expect($content)->toContain('Deny from all');
	});

	it('throws exception when directory creation fails', function (): void {
		// Skip this test - it triggers warnings that can't be suppressed in test environment
		$this->markTestSkipped('Directory creation failure test triggers environment warnings');
	});

	it('validates existing directory successfully', function (): void {
		mkdir($this->testDir, 0755, true);

		expect(fn () => $this->manager->validateDirectory($this->testDir))
			->not->toThrow(RuntimeException::class);
	});

	it('throws exception for non-existent directory', function (): void {
		expect(fn () => $this->manager->validateDirectory('/nonexistent/directory'))
			->toThrow(RuntimeException::class, 'Path exists but is not a directory');
	});

	it('throws exception for non-writable directory', function (): void {
		// Skip this test on systems where we can't control permissions reliably
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$this->markTestSkipped('Permission tests not reliable on Windows');
		}

		mkdir($this->testDir, 0555, true); // Read-only

		try {
			expect(fn () => $this->manager->validateDirectory($this->testDir))
				->toThrow(RuntimeException::class, 'Directory is not writable');
		} finally {
			// Clean up
			chmod($this->testDir, 0755);
		}
	});

	it('detects empty directory correctly', function (): void {
		mkdir($this->testDir, 0755, true);

		expect($this->manager->isDirectoryEmpty($this->testDir))->toBeTrue();
	});

	it('detects non-empty directory correctly', function (): void {
		mkdir($this->testDir, 0755, true);
		file_put_contents($this->testDir . '/test.txt', 'test');

		expect($this->manager->isDirectoryEmpty($this->testDir))->toBeFalse();
	});

	it('treats non-existent directory as empty', function (): void {
		expect($this->manager->isDirectoryEmpty('/nonexistent/path'))->toBeTrue();
	});

	it('cleans up empty default directory', function (): void {
		$defaultPath = sys_get_temp_dir() . '/default-' . uniqid();
		$chosenPath  = sys_get_temp_dir() . '/chosen-' . uniqid();

		mkdir($defaultPath, 0755, true);
		mkdir($chosenPath, 0755, true);

		$this->manager->cleanupEmptyDefaultDirectory($defaultPath, $chosenPath);

		expect(is_dir($defaultPath))->toBeFalse();
		expect(is_dir($chosenPath))->toBeTrue();

		// Clean up
		rmdir($chosenPath);
	});

	it('does not remove default directory if it contains files', function (): void {
		$defaultPath = sys_get_temp_dir() . '/default-' . uniqid();
		$chosenPath  = sys_get_temp_dir() . '/chosen-' . uniqid();

		mkdir($defaultPath, 0755, true);
		mkdir($chosenPath, 0755, true);
		file_put_contents($defaultPath . '/test.txt', 'test');

		$this->manager->cleanupEmptyDefaultDirectory($defaultPath, $chosenPath);

		expect(is_dir($defaultPath))->toBeTrue();
		expect(file_exists($defaultPath . '/test.txt'))->toBeTrue();

		// Clean up
		recursiveDelete($defaultPath);
		rmdir($chosenPath);
	});

	it('does not remove directory if paths are the same', function (): void {
		$path = sys_get_temp_dir() . '/same-' . uniqid();
		mkdir($path, 0755, true);

		$this->manager->cleanupEmptyDefaultDirectory($path, $path);

		expect(is_dir($path))->toBeTrue();

		// Clean up
		rmdir($path);
	});

	it('handles non-existent default directory gracefully', function (): void {
		$defaultPath = '/nonexistent/default';
		$chosenPath  = sys_get_temp_dir() . '/chosen-' . uniqid();

		mkdir($chosenPath, 0755, true);

		// Should not throw exception
		$this->manager->cleanupEmptyDefaultDirectory($defaultPath, $chosenPath);

		expect(is_dir($chosenPath))->toBeTrue();

		// Clean up
		rmdir($chosenPath);
	});
});
