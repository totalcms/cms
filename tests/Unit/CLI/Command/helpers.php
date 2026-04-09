<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use TotalCMS\Support\Config;

/**
 * Create a Config instance with all required fields for CLI command tests.
 *
 * @param array<string,mixed> $overrides
 */
function createTestConfig(array $overrides = []): Config
{
	$defaults = [
		'env'             => 'test',
		'template'        => '/tmp/templates',
		'dashboard'       => [],
		'datadir'         => '/tmp/tcms-data',
		'tmpdir'          => '/tmp',
		'cachedir'        => '/tmp/cache',
		'cache'           => [],
		'logger'          => [],
		'sentry'          => false,
		'error'           => [],
		'imageworks'      => [],
		'domain'          => 'example.com',
		'url'             => 'https://example.com',
		'api'             => '/',
		'locale'          => 'en_US',
		'session'         => [],
		'auth'            => [],
		'debug'           => false,
		'notfound'        => '/404',
		'maxDownloadSize' => 2048,
		'htmlclean'       => [],
		'smtp'            => [],
		'mailer'          => [],
		'pushnotif'       => [],
		'presets'         => [],
	];

	return new Config(array_merge($defaults, $overrides));
}

