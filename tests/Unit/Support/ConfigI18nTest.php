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

	test('top-level $settings[locale] wins over i18n.default for $config->locale', function () use ($baseline): void {
		// Advanced split: operators who need formatting to differ from the
		// content default set `$settings['locale']` at the top level in tcms.php.
		// That override wins for `$config->locale` while `i18n.default` keeps
		// driving content defaults.
		$settings = array_merge($baseline, [
			'locale' => 'en_US',                                  // formatting locale
			'i18n'   => ['default' => 'de', 'available' => []],   // content default
		]);

		$config = new Config($settings);

		expect($config->locale)->toBe('en_US');
		expect($config->i18n['default'])->toBe('de');
	});

	test('without top-level locale, $config->locale derives from i18n.default', function () use ($baseline): void {
		// Common case: operators set a single default locale. Both formatting
		// and content default mirror it.
		$noLocaleBaseline = $baseline;
		unset($noLocaleBaseline['locale']);

		$settings = array_merge($noLocaleBaseline, [
			'i18n' => ['default' => 'de_DE', 'available' => []],
		]);

		$config = new Config($settings);

		expect($config->locale)->toBe('de_DE');
		expect($config->i18n['default'])->toBe('de_DE');
	});

	test('with neither top-level locale nor i18n.default, $config->locale falls back to en_US', function () use ($baseline): void {
		$noLocaleBaseline = $baseline;
		unset($noLocaleBaseline['locale']);

		$config = new Config($noLocaleBaseline);

		expect($config->locale)->toBe('en_US');
		expect($config->i18n['default'])->toBe('');
	});

	test('in-flight i18n.locale sub-key folds into i18n.default', function () use ($baseline): void {
		// Backwards-compat: the i18n.locale sub-key existed briefly during
		// 3.5 development before the consolidation. If an operator's config
		// still has it, treat it as the default.
		$settings = array_merge($baseline, [
			'i18n' => ['locale' => 'pt_BR', 'available' => []],
		]);

		$config = new Config($settings);

		expect($config->i18n['default'])->toBe('pt_BR');
	});
});
