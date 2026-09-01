<?php

declare(strict_types=1);

use Tests\Fakes\DevModeRaceStreamWrapper;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Support\Config;

// The race lives in the gap between DevModeManager's two reads of the state
// file, so these tests drive each read individually through a stream wrapper
// standing in for a concurrent writer. Before this file, every line handling
// that gap had zero coverage — only another process can reach it.

beforeEach(function (): void {
	DevModeRaceStreamWrapper::reset();
	if (in_array('devmoderace', stream_get_wrappers(), true)) {
		stream_wrapper_unregister('devmoderace');
	}
	stream_wrapper_register('devmoderace', DevModeRaceStreamWrapper::class);

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = 'devmoderace://data';

	$this->manager = new DevModeManager(new EventDispatcher(new Psr\Log\NullLogger()), $config);
});

afterEach(function (): void {
	if (in_array('devmoderace', stream_get_wrappers(), true)) {
		stream_wrapper_unregister('devmoderace');
	}
});

it('removes a state file that is corrupt and stays corrupt', function (): void {
	DevModeRaceStreamWrapper::$alwaysCorrupt = true;

	$status = $this->manager->getDevModeStatus();

	expect($status['enabled'])->toBeFalse()
		->and($status['remaining_formatted'])->toBe('0:00:00')
		// The self-heal is the reason this retries rather than returning the
		// disabled array outright: a corrupt file gets cleared for the next
		// request. Losing this is the trap in "just don't recurse".
		->and(DevModeRaceStreamWrapper::$unlinks)->toBe(1);
});

it('recovers when only the payload read is corrupt', function (): void {
	DevModeRaceStreamWrapper::$corruptPayloadReads = 1;

	$status = $this->manager->getDevModeStatus();

	// The file was fine again on the retry, so the honest answer is "enabled" —
	// one bad read mid-write must not report dev mode off.
	expect($status['enabled'])->toBeTrue()
		->and(DevModeRaceStreamWrapper::$unlinks)->toBe(0);
});

it('gives up after one retry instead of recursing without a bound', function (): void {
	// A writer alternating valid and corrupt content in step with the reads.
	// This method used to call itself with no depth limit, and this pattern
	// drove it to a SIGSEGV — not an exception a caller could catch.
	DevModeRaceStreamWrapper::$corruptPayloadReads = 5;

	$status = $this->manager->getDevModeStatus();

	// 4 reads: check, corrupt payload, re-check, corrupt payload, stop.
	// Unbounded, it would keep going until the flips ran out and then report
	// enabled, so both halves of this assertion pin the bound.
	expect($status['enabled'])->toBeFalse()
		->and(DevModeRaceStreamWrapper::$opens)->toBeLessThanOrEqual(4);
});

it('survives the file vanishing between its two reads', function (): void {
	// Installs that promote warnings to exceptions turn a read of a
	// just-unlinked file into an ErrorException. getDevModeStatus() caught only
	// \JsonException, so it escaped to the caller — see f93407d8f for the same
	// class of failure taking down container construction.
	DevModeRaceStreamWrapper::$vanishOnSecondRead = true;

	set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
		throw new ErrorException($str, 0, $no, $file, $line);
	});

	try {
		$status = $this->manager->getDevModeStatus();
	} finally {
		restore_error_handler();
	}

	expect($status)->toHaveKeys(['enabled', 'remaining_seconds', 'remaining_formatted', 'expires_at', 'started_at']);
});

it('does not delete the state file when the read itself fails', function (): void {
	// A failed read says nothing about the file's contents — the likely cause is
	// another request holding or replacing it. isDevModeActive() deletes on
	// corrupt JSON, deliberately not on this: deleting here would destroy state
	// that is probably still valid, and fire DEVMODE_DISABLED for nobody.
	DevModeRaceStreamWrapper::$vanishOnFirstRead = true;

	set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
		throw new ErrorException($str, 0, $no, $file, $line);
	});

	try {
		$status = $this->manager->getDevModeStatus();
	} finally {
		restore_error_handler();
	}

	expect($status['enabled'])->toBeFalse()
		->and(DevModeRaceStreamWrapper::$unlinks)->toBe(0);
});
