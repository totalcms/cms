<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

/**
 * Coverage for Config's i18n bucket normalization.
 *
 * Config::__construct accepts the canonical `i18n` bucket AND the legacy
 * flat-key shape (`locales` + `defaultLocale`) for backwards compatibility.
 * Both paths land in the same `$config->i18n` structure.
 */
describe('Config i18n normalization', function (): void {
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
		'domain'             => '',
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

	test('canonical i18n bucket is loaded verbatim', function () use ($baseline): void {
		$settings = array_merge($baseline, [
			'i18n' => [
				'default'   => 'de',
				'available' => [
					['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
					['code' => 'de',    'label' => 'Deutsch',      'dir' => 'ltr'],
				],
			],
		]);

		$config = new Config($settings);

		expect($config->i18n['default'])->toBe('de');
		expect($config->i18n['available'])->toHaveCount(2);
		expect($config->i18n['available'][1]['code'])->toBe('de');
	});

	test('legacy flat keys are folded into the i18n bucket', function () use ($baseline): void {
		$settings = array_merge($baseline, [
			'locales' => [
				['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
				['code' => 'pt_BR', 'label' => 'Português',    'dir' => 'ltr'],
			],
			'defaultLocale' => 'pt_BR',
		]);

		$config = new Config($settings);

		expect($config->i18n['default'])->toBe('pt_BR');
		expect($config->i18n['available'])->toHaveCount(2);
		expect($config->i18n['available'][1]['code'])->toBe('pt_BR');
	});

	test('canonical bucket wins when both shapes are present', function () use ($baseline): void {
		$settings = array_merge($baseline, [
			'i18n' => [
				'default'   => 'de',
				'available' => [['code' => 'de', 'label' => 'Deutsch', 'dir' => 'ltr']],
			],
			'locales'       => [['code' => 'en_US', 'label' => 'English', 'dir' => 'ltr']],
			'defaultLocale' => 'en_US',
		]);

		$config = new Config($settings);

		expect($config->i18n['default'])->toBe('de');
		expect($config->i18n['available'])->toHaveCount(1);
		expect($config->i18n['available'][0]['code'])->toBe('de');
	});

	test('empty config produces empty i18n bucket', function () use ($baseline): void {
		$config = new Config($baseline);

		expect($config->i18n['default'])->toBe('');
		expect($config->i18n['available'])->toBe([]);
	});

	test('system locale string at $config->locale stays a string', function () use ($baseline): void {
		// Regression guard for the rename — `locale` is the system locale
		// (PHP intl, CakePHP, Faker); `i18n` is the content-localization
		// bucket. They are deliberately separate.
		$settings = array_merge($baseline, [
			'i18n' => ['default' => 'de', 'available' => []],
		]);

		$config = new Config($settings);

		expect($config->locale)->toBe('en_US');
		expect($config->i18n['default'])->toBe('de');
	});
});
