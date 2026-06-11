<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use TotalCMS\Composer\Plugin;

it('subscribes to composer install and update lifecycle events', function (): void {
	$events = Plugin::getSubscribedEvents();

	expect($events)->toHaveKey(ScriptEvents::POST_INSTALL_CMD);
	expect($events)->toHaveKey(ScriptEvents::POST_UPDATE_CMD);
	expect($events[ScriptEvents::POST_INSTALL_CMD])->toBe('installSkill');
	expect($events[ScriptEvents::POST_UPDATE_CMD])->toBe('installSkill');
});

it('no-ops without throwing when the tcms binary is absent', function (): void {
	$config = $this->createMock(Config::class);
	$config->method('get')->willReturnCallback(fn (string $key): string => match ($key) {
		'bin-dir'    => sys_get_temp_dir() . '/tcms-missing-bin-' . uniqid(),
		'vendor-dir' => sys_get_temp_dir() . '/tcms-missing-vendor-' . uniqid(),
		default      => '',
	});

	$composer = $this->createMock(Composer::class);
	$composer->method('getConfig')->willReturn($config);

	$plugin = new Plugin();
	$plugin->activate($composer, $this->createMock(IOInterface::class));

	// bin-dir has no tcms binary, so the handler must simply return.
	$plugin->installSkill($this->createMock(Event::class));

	expect(true)->toBeTrue();
});
