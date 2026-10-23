<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\Install\ExtensionArchive;
use TotalCMS\Domain\Extension\Service\Install\ExtensionInstaller;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * A private datadir per test: real state file, real extensions directory,
 * no bundled/project/composer extensions unless the test adds them.
 *
 * @return array{installer: ExtensionInstaller, datadir: string, states: ExtensionStateRepository, discovery: ExtensionDiscovery}
 */
function installerHarness(?string $bundledDir = null, ?string $projectDir = null, ?Closure $composer = null, ?Closure $moveTree = null): array
{
	$datadir = tcmsTestTempDir('tcms-ext-install');
	mkdir($datadir . '/.system', 0755, true);
	mkdir($datadir . '/extensions', 0755, true);
	$tmpdir = $datadir . '/tmp';
	mkdir($tmpdir, 0755, true);

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $datadir;
	$config->tmpdir  = $tmpdir;

	$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$discovery = new ExtensionDiscovery(
		$config,
		$validator,
		new NullLogger(),
		$projectDir ?? $datadir . '/no-project-extensions',
		$bundledDir ?? $datadir . '/no-bundled-extensions',
		$composer ?? static fn (): array => [],
	);
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($datadir)));
	$states  = new ExtensionStateRepository($storage);

	$installer = new ExtensionInstaller(new ExtensionArchive($validator), $discovery, $states, $config, new NullLogger(), moveTree: $moveTree);

	return ['installer' => $installer, 'datadir' => $datadir, 'states' => $states, 'discovery' => $discovery];
}

function thingZip(string $version = '1.0.0', bool $risky = false, string $wrapper = 'thing'): string
{
	return buildTestZip([
		"{$wrapper}/extension.json" => testExtensionManifest('acme/thing', $version),
		"{$wrapper}/Extension.php"  => testExtensionClass($risky),
	]);
}

afterEach(function (): void {
	// tcmsTestTempDir() only removes an empty dir + .system at shutdown; the
	// install writes a tree, so clean it here.
	foreach (glob(sys_get_temp_dir() . '/tcms-ext-install-*') ?: [] as $dir) {
		recursiveDelete($dir);
	}
});

describe('ExtensionInstaller fresh install', function (): void {
	test('installs under the manifest id, not the wrapper folder name', function (): void {
		['installer' => $installer, 'datadir' => $datadir, 'states' => $states] = installerHarness();

		$result = $installer->install(thingZip('1.0.0', false, 'my-ext-v2'));

		expect($result->id)->toBe('acme/thing')
			->and($result->isUpdate)->toBeFalse()
			->and($result->previousVersion)->toBeNull()
			->and($result->path)->toBe($datadir . '/extensions/acme/thing')
			->and(is_file($datadir . '/extensions/acme/thing/extension.json'))->toBeTrue()
			->and(is_dir($datadir . '/extensions/my-ext-v2'))->toBeFalse()
			->and($states->getState('acme/thing'))->toBeNull();
	});

	test('reports scan findings but still installs, disabled', function (): void {
		['installer' => $installer, 'states' => $states] = installerHarness();

		$result = $installer->install(thingZip('1.0.0', true));

		expect($result->findings)->toBeGreaterThan(0)
			->and($result->disabled)->toBeFalse()
			->and($states->isEnabled('acme/thing'))->toBeFalse();
	});

	test('leaves no staging directory behind', function (): void {
		['installer' => $installer, 'datadir' => $datadir] = installerHarness();

		$installer->install(thingZip());

		$staging = glob($datadir . '/tmp/extension-install/*') ?: [];
		expect($staging)->toBe([]);
	});

	test('passes archive warnings through', function (): void {
		['installer' => $installer] = installerHarness();
		$zip                        = buildTestZip([
			'thing/extension.json' => testExtensionManifest('acme/thing'),
			'thing/composer.json'  => json_encode(['require' => ['monolog/monolog' => '^3']]),
		]);

		expect($installer->install($zip)->warnings)->toHaveCount(1);
	});
});

describe('ExtensionInstaller update', function (): void {
	test('replaces the files and keeps state, permissions and settings', function (): void {
		['installer' => $installer, 'datadir' => $datadir, 'states' => $states] = installerHarness();
		$installer->install(thingZip('1.0.0'));
		file_put_contents($datadir . '/extensions/acme/thing/old-only.txt', 'gone after update');
		$states->saveState('acme/thing', new ExtensionState(enabled: true, installedAt: '2026-01-01', version: '1.0.0', permissions: ['twig:functions' => false]));
		mkdir($datadir . '/.system/extension-settings/acme', 0755, true);
		file_put_contents($datadir . '/.system/extension-settings/acme/thing.json', '{"apiKey":"keep"}');

		$result = $installer->install(thingZip('1.1.0'));

		$state = $states->getState('acme/thing');
		expect($result->isUpdate)->toBeTrue()
			->and($result->previousVersion)->toBe('1.0.0')
			->and($result->version)->toBe('1.1.0')
			->and(file_exists($datadir . '/extensions/acme/thing/old-only.txt'))->toBeFalse()
			->and($state?->enabled)->toBeTrue()
			->and($state?->version)->toBe('1.1.0')
			->and($state?->installedAt)->toBe('2026-01-01')
			->and($state?->permissions)->toBe(['twig:functions' => false])
			->and(file_get_contents($datadir . '/.system/extension-settings/acme/thing.json'))->toBe('{"apiKey":"keep"}')
			->and(glob($datadir . '/extensions/acme/thing.bak-*') ?: [])->toBe([])
			->and(glob($datadir . '/extensions/.backup-*') ?: [])->toBe([]);
	});

	test('clears a previous error and quarantine', function (): void {
		['installer' => $installer, 'states' => $states] = installerHarness();
		$installer->install(thingZip('1.0.0'));
		$states->saveState('acme/thing', new ExtensionState(
			enabled: true,
			version: '1.0.0',
			error: 'boom',
			quarantine: ['reason' => 'crashed', 'failureCount' => 5, 'lastError' => 'x', 'quarantinedAt' => '2026-01-01'],
		));

		$installer->install(thingZip('1.0.1'));

		$state = $states->getState('acme/thing');
		expect($state?->error)->toBeNull()
			->and($state?->isQuarantined())->toBeFalse();
	});

	test('a risky same-version update of an enabled extension disables it', function (): void {
		['installer' => $installer, 'states' => $states] = installerHarness();
		$installer->install(thingZip('1.0.0'));
		// Approved while clean, so the risky code in the upload is new.
		$states->saveState('acme/thing', new ExtensionState(enabled: true, version: '1.0.0', approvedFindings: []));

		$result = $installer->install(thingZip('1.0.0', true));

		$state = $states->getState('acme/thing');
		expect($result->disabled)->toBeTrue()
			->and($state?->enabled)->toBeFalse()
			->and($state?->updateDisabled)->not->toBeNull()
			->and($state?->updateDisabled['findings'] ?? 0)->toBe($result->findings);
	});

	test('an update carrying only the risky code already approved stays enabled', function (): void {
		['installer' => $installer, 'states' => $states] = installerHarness();
		$first                                           = $installer->install(thingZip('1.0.0', true));
		expect($first->findings)->toBeGreaterThan(0);

		// The operator enabled 1.0.0 with its findings, as ExtensionManager::enable() records them.
		$states->saveState('acme/thing', new ExtensionState(enabled: true, version: '1.0.0', approvedFindings: null));
		$installer->install(thingZip('1.0.1', true)); // legacy record: adopts these findings as approved
		$result = $installer->install(thingZip('1.0.2', true));

		$state = $states->getState('acme/thing');
		expect($result->disabled)->toBeFalse()
			->and($state?->enabled)->toBeTrue()
			->and($state?->updateDisabled)->toBeNull()
			->and($state?->approvedFindings)->not->toBe([])
			->and($state?->version)->toBe('1.0.2');
	});

	test('a risky update of a disabled extension is recorded but changes nothing else', function (): void {
		['installer' => $installer, 'states' => $states] = installerHarness();
		$installer->install(thingZip('1.0.0'));
		$states->saveState('acme/thing', new ExtensionState(enabled: false, version: '1.0.0'));

		$result = $installer->install(thingZip('1.1.0', true));

		$state = $states->getState('acme/thing');
		expect($result->disabled)->toBeFalse()
			->and($state?->enabled)->toBeFalse()
			->and($state?->updateDisabled)->toBeNull()
			->and($state?->version)->toBe('1.1.0');
	});

	test('restores the previous directory when the swap fails', function (): void {
		['installer' => $installer, 'datadir' => $datadir] = installerHarness();
		$installer->install(thingZip('1.0.0'));
		$target = $datadir . '/extensions/acme/thing';
		file_put_contents($target . '/marker.txt', 'v1');

		// A read-only parent makes the rename of the existing directory fail.
		chmod($datadir . '/extensions/acme', 0555);

		try {
			expect(fn () => $installer->install(thingZip('1.1.0')))
				->toThrow(InstallException::class);
		} finally {
			chmod($datadir . '/extensions/acme', 0755);
		}

		expect(file_get_contents($target . '/marker.txt'))->toBe('v1')
			->and(file_get_contents($target . '/extension.json'))->toContain('"1.0.0"');
	})->skip(posix_geteuid() === 0, 'root ignores directory permissions');
});

describe('ExtensionInstaller failure handling', function (): void {
	test('restores the previous directory when the move into place fails', function (): void {
		$fail = static function (string $staging, string $target): void {
			// Simulate a half-written copy, then the failure.
			mkdir($target, 0755, true);
			file_put_contents($target . '/partial.txt', 'half');

			throw new RuntimeException('Could not copy: extension.json');
		};
		['installer' => $installer, 'datadir' => $datadir] = installerHarness(moveTree: $fail);
		$target                                            = $datadir . '/extensions/acme/thing';
		mkdir($target, 0755, true);
		file_put_contents($target . '/extension.json', testExtensionManifest('acme/thing', '1.0.0'));
		file_put_contents($target . '/Extension.php', testExtensionClass());
		file_put_contents($target . '/marker.txt', 'v1');

		expect(fn () => $installer->install(thingZip('1.1.0')))->toThrow(InstallException::class);

		expect(file_get_contents($target . '/marker.txt'))->toBe('v1')
			->and(file_get_contents($target . '/extension.json'))->toContain('"1.0.0"')
			->and(file_exists($target . '/partial.txt'))->toBeFalse()
			->and(glob($datadir . '/extensions/acme/thing.bak-*') ?: [])->toBe([])
			->and(glob($datadir . '/extensions/.backup-*') ?: [])->toBe([]);
	});

	test('moves the old copy aside to a dot folder discovery ignores, then deletes it', function (): void {
		$seen     = [];
		$move     = static function (string $staging, string $target) use (&$seen, &$datadir): void {
			$seen = [
				'backups'  => glob($datadir . '/extensions/.backup-acme-thing-*') ?: [],
				'inVendor' => glob($datadir . '/extensions/acme/*') ?: [],
			];
			rename($staging, $target);
		};
		['installer' => $installer, 'datadir' => $datadir] = installerHarness(moveTree: $move);
		$installer->install(thingZip('1.0.0'));

		$installer->install(thingZip('1.1.0'));

		expect($seen['backups'])->toHaveCount(1)
			->and(is_file($seen['backups'][0] . '/extension.json'))->toBeFalse() // deleted after success
			->and($seen['inVendor'])->toBe([])
			->and(glob($datadir . '/extensions/.backup-*') ?: [])->toBe([])
			->and(file_get_contents($datadir . '/extensions/acme/thing/extension.json'))->toContain('"1.1.0"');
	});

	test('a risky update is disabled in state before the new files land', function (): void {
		$enabledAtMove = null;
		$states        = null;
		$move          = static function (string $staging, string $target) use (&$enabledAtMove, &$states): void {
			$enabledAtMove = $states?->isEnabled('acme/thing');
			rename($staging, $target);
		};
		['installer' => $installer, 'states' => $states] = installerHarness(moveTree: $move);
		$installer->install(thingZip('1.0.0'));
		$states->saveState('acme/thing', new ExtensionState(enabled: true, version: '1.0.0', approvedFindings: []));

		$result = $installer->install(thingZip('1.0.0', true));

		expect($enabledAtMove)->toBeFalse()
			->and($result->disabled)->toBeTrue()
			->and($states->isEnabled('acme/thing'))->toBeFalse();
	});

	test('a failed swap puts the previous state back with the previous files', function (): void {
		$fail = static function (string $staging, string $target): void {
			throw new RuntimeException('disk full');
		};
		['installer' => $installer, 'datadir' => $datadir, 'states' => $states]  = installerHarness(moveTree: $fail);
		$target                                                                  = $datadir . '/extensions/acme/thing';
		mkdir($target, 0755, true);
		file_put_contents($target . '/extension.json', testExtensionManifest('acme/thing', '1.0.0'));
		file_put_contents($target . '/Extension.php', testExtensionClass());
		$states->saveState('acme/thing', new ExtensionState(enabled: true, version: '1.0.0'));

		expect(fn () => $installer->install(thingZip('1.0.0', true)))->toThrow(InstallException::class, 'disk full');

		$state = $states->getState('acme/thing');
		expect($state?->enabled)->toBeTrue()
			->and($state?->updateDisabled)->toBeNull()
			->and(file_get_contents($target . '/extension.json'))->toContain('"1.0.0"');
	});

	test('a fresh install drops orphaned enabled state so it lands disabled', function (): void {
		['installer' => $installer, 'states' => $states] = installerHarness();
		$states->saveState('acme/thing', new ExtensionState(enabled: true, version: '0.9.0'));

		$installer->install(thingZip('1.0.0', true));

		expect($states->getState('acme/thing'))->toBeNull()
			->and($states->isEnabled('acme/thing'))->toBeFalse();
	});
});

describe('ExtensionInstaller ownership', function (): void {
	test('refuses an id that a bundled extension owns', function (): void {
		$bundled = tcmsTestTempDir('tcms-ext-install-bundled');
		mkdir($bundled . '/acme/thing', 0755, true);
		file_put_contents($bundled . '/acme/thing/extension.json', testExtensionManifest('acme/thing'));
		file_put_contents($bundled . '/acme/thing/Extension.php', testExtensionClass());
		['installer' => $installer, 'datadir' => $datadir] = installerHarness(bundledDir: $bundled);

		expect(fn () => $installer->install(thingZip()))
			->toThrow(InstallException::class, 'bundled');
		expect(is_dir($datadir . '/extensions/acme/thing'))->toBeFalse();
	});

	test('refuses an id that a project extension owns', function (): void {
		$project = tcmsTestTempDir('tcms-ext-install-project');
		mkdir($project . '/acme/thing', 0755, true);
		file_put_contents($project . '/acme/thing/extension.json', testExtensionManifest('acme/thing'));
		file_put_contents($project . '/acme/thing/Extension.php', testExtensionClass());
		['installer' => $installer] = installerHarness(projectDir: $project);

		expect(fn () => $installer->install(thingZip()))
			->toThrow(InstallException::class, 'project extensions directory');
	});

	test('refuses an id that a Composer package owns', function (): void {
		$vendor = tcmsTestTempDir('tcms-ext-install-composer');
		mkdir($vendor . '/acme/thing', 0755, true);
		file_put_contents($vendor . '/acme/thing/extension.json', testExtensionManifest('acme/thing'));
		file_put_contents($vendor . '/acme/thing/Extension.php', testExtensionClass());
		['installer' => $installer] = installerHarness(composer: static fn (): array => ['acme/thing-pkg' => ['path' => $vendor . '/acme/thing', 'version' => '1.0.0']]);

		expect(fn () => $installer->install(thingZip()))
			->toThrow(InstallException::class, 'composer remove acme/thing-pkg');
	});

	test('refuses an update when the existing copy is not at vendor/name', function (): void {
		['installer' => $installer, 'datadir' => $datadir] = installerHarness();
		mkdir($datadir . '/extensions/foo/bar', 0755, true);
		file_put_contents($datadir . '/extensions/foo/bar/extension.json', testExtensionManifest('acme/thing', '1.0.0'));
		file_put_contents($datadir . '/extensions/foo/bar/Extension.php', testExtensionClass());

		expect(fn () => $installer->install(thingZip('1.1.0')))
			->toThrow(InstallException::class, 'Remove the existing copy first');
		expect(fn () => $installer->plan(thingZip('1.1.0')))
			->toThrow(InstallException::class, $datadir . '/extensions/foo/bar');
		expect(is_dir($datadir . '/extensions/acme/thing'))->toBeFalse()
			->and(file_get_contents($datadir . '/extensions/foo/bar/extension.json'))->toContain('"1.0.0"');
	});

	test('plan() reports an update without writing anything', function (): void {
		['installer' => $installer, 'datadir' => $datadir] = installerHarness();
		$installer->install(thingZip('1.0.0'));

		$plan = $installer->plan(thingZip('2.0.0'));

		expect($plan->isUpdate())->toBeTrue()
			->and($plan->existing?->version)->toBe('1.0.0')
			->and(file_get_contents($datadir . '/extensions/acme/thing/extension.json'))->toContain('"1.0.0"');
	});
});
