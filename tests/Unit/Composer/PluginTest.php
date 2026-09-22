<?php

declare(strict_types=1);

namespace Tests\Unit\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use TotalCMS\Composer\Plugin;

/**
 * Composer runs `scripts` only from the root project, so this plugin is the
 * one way `totalcms/cms` can run project-side work on a customer's install or
 * update. Every handler shells out to `vendor/bin/tcms` and must never abort
 * the composer run.
 */
it('installs the skill on install and update, and deploys on update only', function (): void {
	$events = Plugin::getSubscribedEvents();

	expect($events[ScriptEvents::POST_INSTALL_CMD])->toBe('installSkill');

	// Update runs both. The skill lands first (higher priority runs first in
	// Composer's dispatcher); deploy's cache wipe then cannot undo anything.
	$onUpdate = $events[ScriptEvents::POST_UPDATE_CMD];
	expect($onUpdate)->toBe([['installSkill', 10], ['deploy', 0]]);
});

it('does not deploy on a plain install', function (): void {
	// `composer install` of a lockfile-pinned, unchanged version needs no cache
	// wipe or migrations; only a version change (update) does.
	$events = Plugin::getSubscribedEvents();

	expect($events[ScriptEvents::POST_INSTALL_CMD])->not->toContain('deploy');
});

beforeEach(function (): void {
	// bin-dir has no tcms binary (--no-scripts, partial install): every handler must simply return.
	$config = $this->createMock(Config::class);
	$config->method('get')->willReturnCallback(fn (string $key): string => match ($key) {
		'bin-dir'    => sys_get_temp_dir() . '/tcms-missing-bin-' . uniqid(),
		'vendor-dir' => sys_get_temp_dir() . '/tcms-missing-vendor-' . uniqid(),
		default      => '',
	});

	$composer = $this->createMock(Composer::class);
	$composer->method('getConfig')->willReturn($config);

	$this->plugin = new Plugin();
	$this->plugin->activate($composer, $this->createMock(IOInterface::class));
});

it('the skill handler no-ops without throwing when the tcms binary is absent', function (): void {
	$this->plugin->installSkill($this->createMock(Event::class));

	expect(true)->toBeTrue();
});

it('the deploy handler no-ops without throwing when the tcms binary is absent', function (): void {
	$this->plugin->deploy($this->createMock(Event::class));

	expect(true)->toBeTrue();
});

it('runs the real tcms binary from the project root with the deploy command', function (): void {
	// A stand-in tcms that records how it was called. Proves the shell-out end
	// to end — binary resolution, escaping, working directory — without Composer.
	$root = sys_get_temp_dir() . '/tcms-plugin-' . uniqid();
	mkdir("$root/vendor/bin", 0777, true);
	$log = "$root/calls.log";
	file_put_contents("$root/vendor/bin/tcms", "<?php file_put_contents(" . var_export($log, true) . ", implode(' ', array_slice(\$argv, 1)) . '|' . getcwd()); echo 'Deploy cleanup complete.';");

	$config = $this->createMock(Config::class);
	$config->method('get')->willReturnCallback(fn (string $key): string => match ($key) {
		'bin-dir'    => "$root/vendor/bin",
		'vendor-dir' => "$root/vendor",
		default      => '',
	});
	$composer = $this->createMock(Composer::class);
	$composer->method('getConfig')->willReturn($config);

	$io = $this->createMock(IOInterface::class);
	$io->expects($this->never())->method('writeError');
	$written = [];
	$io->method('write')->willReturnCallback(function (string|array $messages) use (&$written): void {
		$written[] = is_array($messages) ? implode("\n", $messages) : $messages;
	});

	$plugin = new Plugin();
	$plugin->activate($composer, $io);
	$plugin->deploy($this->createMock(Event::class));

	[$args, $cwd] = explode('|', (string)file_get_contents($log));
	expect($args)->toBe('deploy')
		->and(realpath($cwd))->toBe(realpath($root))
		// The command's own output reaches the operator, then the summary line.
		->and(implode("\n", $written))->toContain('Deploy cleanup complete.')
		->and(end($written))->toContain('deploy cleanup complete');
});

