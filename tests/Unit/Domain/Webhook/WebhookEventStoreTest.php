<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Webhook\WebhookEventStore;

// A provider sends the same webhook again when it gets no answer, sometimes
// days later. The store remembers which events were handled, for a week.

beforeEach(function (): void {
	$this->dir = tcmsTestTempDir('webhook-events');
	mkdir($this->dir . '/.system', 0755, true);
	$this->file = $this->dir . '/.system/webhook-events.json';

	$this->store = fn (): WebhookEventStore => new WebhookEventStore(new AtomicJsonStore(
		new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->dir))),
		$this->dir,
		new NullLogger(),
	));

	$this->stored = fn (): array => (array)json_decode((string)file_get_contents($this->file), true);
});

afterEach(fn () => recursiveDelete($this->dir, forceComplete: true));

it('has seen an event once it is remembered, and not before', function (): void {
	$store = ($this->store)();

	expect($store->seen('stripe:evt_1'))->toBeFalse();

	$store->remember('stripe:evt_1');

	expect($store->seen('stripe:evt_1'))->toBeTrue()
		->and($store->seen('stripe:evt_2'))->toBeFalse()
		// Another request, another instance: it is on disk.
		->and(($this->store)()->seen('stripe:evt_1'))->toBeTrue();
});

it('says first time once, in one step', function (): void {
	$store = ($this->store)();

	expect($store->firstSeen('ses', 'a'))->toBeTrue()
		->and($store->firstSeen('ses', 'a'))->toBeFalse()
		->and(($this->store)()->firstSeen('ses', 'a'))->toBeFalse()
		// The same id from another sender is another event.
		->and($store->firstSeen('postmark', 'a'))->toBeTrue()
		->and($store->seen('ses:a'))->toBeTrue();
});

it('forgets an event after seven days, and clears it out on the next write', function (): void {
	file_put_contents($this->file, json_encode([
		'stripe:old'    => time() - 8 * 86400,
		'stripe:recent' => time() - 6 * 86400,
	]));
	$store = ($this->store)();

	expect($store->seen('stripe:old'))->toBeFalse()
		->and($store->seen('stripe:recent'))->toBeTrue()
		->and($store->firstSeen('stripe', 'old'))->toBeTrue();

	$store->remember('stripe:new');

	expect(array_keys(($this->stored)()))->toBe(['stripe:recent', 'stripe:old', 'stripe:new'])
		->and(($this->stored)()['stripe:old'])->toBeGreaterThan(time() - 60);
});

it('prunes expired events when it writes', function (): void {
	file_put_contents($this->file, json_encode(['stripe:old' => time() - 8 * 86400, 'stripe:recent' => time() - 60]));

	($this->store)()->remember('stripe:new');

	expect(array_keys(($this->stored)()))->toBe(['stripe:recent', 'stripe:new']);
});

it('reads a file it cannot parse as empty: an event handled twice is safer than none handled', function (): void {
	file_put_contents($this->file, '{"stripe:evt_1": 17');
	$store = ($this->store)();

	expect($store->seen('stripe:evt_1'))->toBeFalse()
		->and($store->firstSeen('stripe', 'evt_2'))->toBeTrue()
		->and($store->seen('stripe:evt_2'))->toBeTrue();
});

it('keeps a very long key short, and still tells two of them apart', function (): void {
	$store = ($this->store)();
	$a     = 'stripe:' . str_repeat('x', 500) . 'a';
	$b     = 'stripe:' . str_repeat('x', 500) . 'b';

	$store->remember($a);

	expect($store->seen($a))->toBeTrue()
		->and($store->seen($b))->toBeFalse()
		->and(max(array_map(strlen(...), array_keys(($this->stored)()))))->toBeLessThanOrEqual(200);
});

it('never remembers an empty key', function (): void {
	$store = ($this->store)();
	$store->remember('');

	expect($store->seen(''))->toBeFalse()
		->and(file_exists($this->file))->toBeFalse();
});
