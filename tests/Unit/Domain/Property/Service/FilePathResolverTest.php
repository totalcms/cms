<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\FilePathResolver;
use TotalCMS\Support\Config;

beforeEach(function (): void {
	$this->root = sys_get_temp_dir() . '/tcms-paths-' . uniqid();
	mkdir($this->root . '/depot/kit/depot/docs/2026', 0755, true);
	file_put_contents($this->root . '/depot/kit/depot/docs/2026/a.pdf', 'pdf');
	file_put_contents($this->root . '/depot/kit/depot/root.txt', 'txt');

	$config          = test()->createMock(Config::class);
	$config->datadir = $this->root;
	$this->fetcher   = test()->createMock(FileFetcher::class);
	$this->resolver  = new FilePathResolver($this->fetcher, $config);
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->root));
});

test('a file property resolves to its stored file', function (): void {
	$this->fetcher->method('fetchFile')->with('downloads', 'guide', 'file')->willReturn(new FileData(['name' => 'guide.pdf']));

	expect($this->resolver->filePath('guide', ['collection' => 'downloads']))->toBe($this->root . '/downloads/guide/file/guide.pdf');
});

test('a file property that cannot be fetched is null', function (): void {
	$this->fetcher->method('fetchFile')->willThrowException(new RuntimeException('no such object'));

	expect($this->resolver->filePath('missing'))->toBeNull();
});

test('a depot path splits folders from the filename and checks the file exists', function (): void {
	expect($this->resolver->depotPath('kit', 'docs/2026/a.pdf'))->toBe($this->root . '/depot/kit/depot/docs/2026/a.pdf')
		->and($this->resolver->depotPath('kit', 'root.txt'))->toBe($this->root . '/depot/kit/depot/root.txt')
		->and($this->resolver->depotPath('kit', 'docs/none.pdf'))->toBeNull();
});
