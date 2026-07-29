<?php

declare(strict_types=1);

use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Support\Config;

/*
 * `Config::init()` memoizes the settings array (see ConfigInitMemoTest), and
 * `config/settings.php` merges settings.json off disk. That means the memo goes
 * stale the instant settings.json is rewritten — so SettingsSaver::writeSettings(),
 * the single funnel for every write, drops it via Config::reset().
 *
 * Without that call an operator saving a setting would keep seeing the old value
 * for the rest of the request. This exercises the real repository against the
 * test datadir rather than mocking the write away, because the whole point is
 * that the bytes on disk and the next Config::init() agree.
 */

beforeEach(function (): void {
	$this->setUpApp(bootstrap());

	$this->settingsFile = cmsDataDir() . '.system/settings.json';
	$this->original     = file_exists($this->settingsFile)
		? file_get_contents($this->settingsFile)
		: null;
});

afterEach(function (): void {
	// Put settings.json back exactly as found so this test can't leak into others.
	if ($this->original === null) {
		@unlink($this->settingsFile);
	} else {
		file_put_contents($this->settingsFile, $this->original);
	}

	Config::reset();
});

test('a saved setting is visible to the very next Config::init()', function (): void {
	// Prime the memo first — a stale read is only observable if something was
	// cached before the write.
	$before = Config::init()->siteName;

	$this->app->getContainer()
		->get(SettingsSaver::class)
		->saveSettings(['siteName' => 'Read After Write']);

	expect($before)->not->toBe('Read After Write')
		->and(Config::init()->siteName)->toBe('Read After Write');
});

test('the written file and the next Config::init() agree', function (): void {
	Config::init();

	$this->app->getContainer()
		->get(SettingsSaver::class)
		->saveSettings(['siteName' => 'On Disk']);

	$onDisk = json_decode((string)file_get_contents($this->settingsFile), true);

	expect($onDisk['siteName'])->toBe('On Disk')
		->and(Config::init()->siteName)->toBe($onDisk['siteName']);
});
