<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SecurityReportStore;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/** @return array{0:SecurityReportStore,1:string} store and its temp datadir */
function securityStoreInTemp(): array
{
	$dir = sys_get_temp_dir() . '/tcms-secstore-' . bin2hex(random_bytes(4));
	mkdir($dir . '/.system', 0777, true);
	$store = new AtomicJsonStore(new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($dir))), $dir, new NullLogger());

	return [new SecurityReportStore($store), $dir];
}

function securityStoreCleanup(string $dir): void
{
	exec('/bin/rm -rf ' . escapeshellarg($dir));
}

it('latest returns what save stored, from a JSON file in .system', function (): void {
	[$store, $dir] = securityStoreInTemp();
	$report        = new SecurityReport([
		SecurityFinding::found('debug-on', Severity::Critical, 'Debug is on', 'detail', 'turn it off', 'docs/x'),
		SecurityFinding::ok('https', 'HTTPS in use'),
	], date('c'));

	$store->save($report);

	expect($store->latest()?->toArray())->toBe($report->toArray())
		->and(is_file($dir . '/.system/security-report.json'))->toBeTrue();
	securityStoreCleanup($dir);
});

it('a second store on the same datadir reads the same report (CLI and web share it)', function (): void {
	[$store, $dir] = securityStoreInTemp();
	$store->save(new SecurityReport([SecurityFinding::ok('a', 'A')], date('c')));

	$other = new SecurityReportStore(new AtomicJsonStore(new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($dir))), $dir, new NullLogger()));

	expect($other->latest()?->findings[0]->id)->toBe('a');
	securityStoreCleanup($dir);
});

it('latest is null when nothing is stored', function (): void {
	[$store, $dir] = securityStoreInTemp();

	expect($store->latest())->toBeNull();
	securityStoreCleanup($dir);
});

it('latest is null when the stored report is older than 24 hours', function (): void {
	[$store, $dir] = securityStoreInTemp();
	$store->save(new SecurityReport([SecurityFinding::ok('a', 'A')], date('c', time() - 86400 - 60)));

	expect($store->latest())->toBeNull();
	securityStoreCleanup($dir);
});

it('latest is null when checkedAt is missing or unreadable', function (): void {
	[$store, $dir] = securityStoreInTemp();
	$store->save(new SecurityReport([SecurityFinding::ok('a', 'A')], 'not a date'));

	expect($store->latest())->toBeNull();
	securityStoreCleanup($dir);
});
