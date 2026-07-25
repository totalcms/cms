<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

/**
 * Coverage for Config::adminTitle() — the admin dashboard browser title.
 *
 * Fallback chain (first match wins):
 *   1. `dashboard.title` when customized away from the shipped default
 *   2. `siteName` + ' Admin' when a site name is set
 *   3. 'Total CMS Admin'
 */
describe('Config adminTitle', function (): void {
	$baseline = [
		'env'                => 'test',
		'template'           => '',
		'dashboard'          => [],
		'datadir'            => '',
		'tmpdir'             => '',
		'cachedir'           => '',
		'cache'              => [],
		'logger'             => [],
		'error'              => [],
		'imageworks'         => [],
		'domain'             => 'example.com',
		'url'                => '',
		'api'                => '',
		'locale'             => 'en_US',
		'session'            => [],
		'auth'               => [],
		'debug'              => false,
		'notfound'           => '',
		'htmlclean'          => [],
		'smtp'               => [],
		'mailer'             => [],
		'pushnotif'          => [],
		'builder'            => [],
	];

	test('falls back to the shipped default with no site name and no custom title', function () use ($baseline): void {
		$config = new Config($baseline);

		expect($config->adminTitle())->toBe('Total CMS Admin');
	});

	test('defaults to "{siteName} Admin" when a site name is set', function () use ($baseline): void {
		$config = new Config(array_merge($baseline, ['siteName' => 'BSH Mission Control']));

		expect($config->adminTitle())->toBe('BSH Mission Control Admin');
	});

	test('treats the shipped default title as not customized', function () use ($baseline): void {
		$config = new Config(array_merge($baseline, [
			'siteName'  => 'BSH Mission Control',
			'dashboard' => ['title' => 'Total CMS Admin'],
		]));

		expect($config->adminTitle())->toBe('BSH Mission Control Admin');
	});

	test('an explicitly customized title wins over the site name', function () use ($baseline): void {
		$config = new Config(array_merge($baseline, [
			'siteName'  => 'BSH Mission Control',
			'dashboard' => ['title' => 'Ops Console'],
		]));

		expect($config->adminTitle())->toBe('Ops Console');
	});

	test('a customized title also wins with no site name set', function () use ($baseline): void {
		$config = new Config(array_merge($baseline, ['dashboard' => ['title' => 'Ops Console']]));

		expect($config->adminTitle())->toBe('Ops Console');
	});
});
