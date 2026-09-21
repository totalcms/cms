<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Seo\IndexNow\IndexNowOutbox;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

// The outbox is a locked JSON set on disk; driven against a real store over
// a temp dir, since mocking the store would test the mock.

describe('IndexNowOutbox', function (): void {
	beforeEach(function (): void {
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-indexnow-outbox-' . uniqid();
		mkdir($this->tmpRoot, 0755, true);
		$storage      = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->tmpRoot)));
		$this->outbox = new IndexNowOutbox(new AtomicJsonStore($storage, $this->tmpRoot, new NullLogger()));
	});

	afterEach(function (): void {
		recursiveDelete($this->tmpRoot, forceComplete: true);
	});

	test('take() returns everything added, once each, and empties the outbox', function (): void {
		$this->outbox->add(['https://example.com/a', 'https://example.com/b']);
		$this->outbox->add(['https://example.com/b', 'https://example.com/c']);

		$taken = $this->outbox->take();
		sort($taken);

		expect($taken)->toBe(['https://example.com/a', 'https://example.com/b', 'https://example.com/c'])
			->and($this->outbox->take())->toBe([]);
	});

	test('take() on a fresh outbox is empty and creates nothing surprising', function (): void {
		expect($this->outbox->take())->toBe([]);
	});

	test('restore() merges unsent URLs back with anything added since', function (): void {
		$this->outbox->add(['https://example.com/a']);
		$batch = $this->outbox->take();
		$this->outbox->add(['https://example.com/new']);

		$this->outbox->restore($batch);
		$taken = $this->outbox->take();
		sort($taken);

		expect($taken)->toBe(['https://example.com/a', 'https://example.com/new']);
	});

	test('clear() discards everything waiting', function (): void {
		$this->outbox->add(['https://example.com/a', 'https://example.com/b']);

		$this->outbox->clear();

		expect($this->outbox->take())->toBe([]);
	});

	test('a corrupt outbox file is treated as empty rather than wedging every save', function (): void {
		mkdir($this->tmpRoot . '/.system', 0755, true);
		file_put_contents($this->tmpRoot . '/.system/indexnow-outbox.json', '{not json');

		$this->outbox->add(['https://example.com/a']);

		expect($this->outbox->take())->toBe(['https://example.com/a']);
	});
});
