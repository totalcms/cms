<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\Sequence;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

// A Sequence hands out numbers people will read, such as order numbers: each
// call the next one, never the same one twice, never one lower than before.

beforeEach(function (): void {
	$this->dir = tcmsTestTempDir('sequence');
	mkdir($this->dir, 0755, true);
	$this->file = $this->dir . '/.system/sequences.json';

	// A new store each time, as a second request or a second process has.
	$this->sequence = fn (): Sequence => new Sequence(new AtomicJsonStore(
		new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->dir))),
		$this->dir,
		new NullLogger(),
	));
});

afterEach(fn () => recursiveDelete($this->dir, forceComplete: true));

it('starts where it is told to and counts up from there', function (): void {
	$sequence = ($this->sequence)();

	expect($sequence->current('orders'))->toBeNull()
		->and($sequence->next('orders', 1001))->toBe(1001)
		->and($sequence->next('orders', 1001))->toBe(1002)
		->and($sequence->current('orders'))->toBe(1002);
});

it('starts at 1 when no start is given', fn () => expect(($this->sequence)()->next('tickets'))->toBe(1));

it('jumps ahead when the start is raised, and is never lowered', function (): void {
	$sequence = ($this->sequence)();
	$sequence->next('orders', 1001);
	$sequence->next('orders', 1001);

	expect($sequence->next('orders', 5000))->toBe(5000)
		// Lowering the start must not hand out a number that was already used.
		->and($sequence->next('orders', 10))->toBe(5001);
});

it('counts each name on its own', function (): void {
	$sequence = ($this->sequence)();

	expect([$sequence->next('orders', 100), $sequence->next('invoices'), $sequence->next('orders', 100), $sequence->next('invoices')])->toBe([100, 1, 101, 2]);
});

it('never gives two callers the same number', function (): void {
	$a      = ($this->sequence)();
	$b      = ($this->sequence)();
	$values = [];
	for ($i = 0; $i < 50; $i++) {
		$values[] = $a->next('orders', 1001);
		$values[] = $b->next('orders', 1001);
	}

	expect(array_unique($values))->toHaveCount(100)
		->and(max($values))->toBe(1100);
});

it('refuses to count over a file it cannot read, and leaves the file alone', function (): void {
	// Starting again from nothing would hand out numbers that were already used.
	mkdir(dirname($this->file), 0755, true);
	file_put_contents($this->file, '{"orders": 10');

	expect(fn () => ($this->sequence)()->next('orders'))->toThrow(RuntimeException::class)
		->and(file_get_contents($this->file))->toBe('{"orders": 10');
});

it('refuses a name that is not a plain slug', function (string $name): void {
	expect(fn () => ($this->sequence)()->next($name))->toThrow(InvalidArgumentException::class)
		->and(fn () => ($this->sequence)()->current($name))->toThrow(InvalidArgumentException::class);
})->with(['../x', 'Orders', 'or ders', '', '-orders', 'a/b']);

it('keeps the numbers in the data folder, where they survive an update', function (): void {
	($this->sequence)()->next('orders', 7);

	expect(json_decode((string)file_get_contents($this->file), true))->toBe(['orders' => 7]);
});
