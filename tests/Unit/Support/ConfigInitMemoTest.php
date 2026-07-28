<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

/*
 * `Config::init()` memoizes the settings array behind it — requiring
 * config/settings.php costs ~200us, and DateData/StringData/CodeData all call
 * init() from their constructors, so that require was being charged per
 * property on every object hydration.
 *
 * What it must NOT memoize is the Config object itself. Callers mutate what
 * they get back: DataPathInstaller syncs `datadir` during the setup wizard, and
 * a long tail of tests poke `env`. Sharing one instance let a single test's
 * `$config->env = 'prod'` leak process-wide, which made ContainerFactory compile
 * the DI container and broke ~700 unrelated tests with an opaque
 * "cannot set a definition at runtime on a compiled container".
 *
 * These tests pin that split so the fast path can't be "simplified" back into a
 * shared singleton.
 */

afterEach(function (): void {
	// Never leave a memo keyed to a mutated environment behind.
	Config::reset();
});

describe('Config::init memoization', function (): void {
	test('hands back a distinct instance on every call', function (): void {
		expect(Config::init())->not->toBe(Config::init());
	});

	test('mutating a returned instance cannot leak into later calls', function (): void {
		$config = Config::init();
		$env    = $config->env;

		$config->env     = 'prod';
		$config->datadir = '/tmp/not-the-real-datadir';

		expect(Config::init()->env)->toBe($env)
			->and(Config::init()->datadir)->not->toBe('/tmp/not-the-real-datadir');
	});

	test('memoized settings still reflect the real config', function (): void {
		// A memo keyed or stored wrongly would surface as empty/default values.
		$config = Config::init();

		expect($config->env)->toBe('test')
			->and($config->datadir)->not->toBe('')
			->and($config->timezone)->not->toBe('');
	});

	test('re-reads settings when APP_ENV changes', function (): void {
		$before = Config::init()->appEnv;

		$_SERVER['APP_ENV'] = 'preview';
		$after              = Config::init()->appEnv;

		// Restore before asserting so a failure can't poison the rest of the run.
		$_SERVER['APP_ENV'] = 'test';
		Config::reset();

		expect($before)->toBe('test')->and($after)->toBe('preview');
	});

	test('reset() leaves init() returning a correct config', function (): void {
		$before = Config::init();
		Config::reset();
		$after = Config::init();

		expect($after->env)->toBe($before->env)
			->and($after->datadir)->toBe($before->datadir);
	});
});
