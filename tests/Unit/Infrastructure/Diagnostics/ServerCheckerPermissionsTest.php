<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Diagnostics;

use TotalCMS\Domain\Bundle\Service\BundleChecker;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Mcp\Service\McpConnectionChecker;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Support\Config;

beforeEach(function (): void {
	$this->root = sys_get_temp_dir() . '/tcms-perms-' . uniqid();
	foreach (['tcms-data', 'cache', 'logs', 'tmp'] as $dir) {
		mkdir("{$this->root}/{$dir}", 0755, true);
	}

	$config           = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir  = "{$this->root}/tcms-data";
	$config->cachedir = "{$this->root}/cache";
	$config->tmpdir   = "{$this->root}/tmp";
	$config->logger   = ['path' => "{$this->root}/logs"];
	$this->config     = $config;

	$this->checker = new ServerChecker(
		$this->createMock(BundleChecker::class),
		$config,
		$this->createMock(LicenseValidator::class),
		$this->createMock(OPcacheService::class),
		$this->createMock(McpConnectionChecker::class),
		$this->createMock(ClientIpResolver::class),
	);
});

afterEach(function (): void {
	foreach (['tcms-data', 'cache', 'logs', 'tmp'] as $dir) {
		@chmod("{$this->root}/{$dir}", 0755);
		@rmdir("{$this->root}/{$dir}");
	}
	@rmdir($this->root);
});

it('reports every directory as writable', function (): void {
	expect($this->checker->checkPermissions())->toBe([
		'tcms-data' => true,
		'cache'     => true,
		'logs'      => true,
		'tmp'       => true,
	]);
});

it('reports a directory that is not writable instead of leaving it out', function (): void {
	chmod("{$this->root}/logs", 0555);

	expect($this->checker->checkPermissions()['logs'])->toBeFalse();
})->skip(posix_geteuid() === 0, 'root can write anywhere');

it('leaves the cache row out when the cache directory is disabled', function (): void {
	$this->config->cachedir = '';

	expect($this->checker->checkPermissions())->not->toHaveKey('cache');
});
