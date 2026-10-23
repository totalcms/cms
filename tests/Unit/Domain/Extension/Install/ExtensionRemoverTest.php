<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\Install\ExtensionRemover;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/** @return array{remover: ExtensionRemover, datadir: string, states: ExtensionStateRepository, manager: ExtensionManager} */
function removerHarness(?string $bundledDir = null): array
{
	$datadir = tcmsTestTempDir('tcms-ext-remove');
	mkdir($datadir . '/.system', 0755, true);
	mkdir($datadir . '/extensions/acme/thing', 0755, true);
	file_put_contents($datadir . '/extensions/acme/thing/extension.json', testExtensionManifest('acme/thing'));
	file_put_contents($datadir . '/extensions/acme/thing/Extension.php', testExtensionClass());

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $datadir;

	$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$discovery = new ExtensionDiscovery(
		$config,
		$validator,
		new NullLogger(),
		$datadir . '/no-project',
		$bundledDir ?? $datadir . '/no-bundled',
		static fn (): array => [],
	);
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($datadir)));
	$states  = new ExtensionStateRepository($storage);
	$states->saveState('acme/thing', new ExtensionState(enabled: true, version: '1.0.0'));

	$manager = test()->createMock(ExtensionManager::class);

	return [
		'remover' => new ExtensionRemover($discovery, $manager, $states),
		'datadir' => $datadir,
		'states'  => $states,
		'manager' => $manager,
	];
}

afterEach(function (): void {
	foreach (glob(sys_get_temp_dir() . '/tcms-ext-remove-*') ?: [] as $dir) {
		recursiveDelete($dir);
	}
});

describe('ExtensionRemover', function (): void {
	test('disables, deletes the directory and drops the state', function (): void {
		['remover' => $remover, 'datadir' => $datadir, 'states' => $states, 'manager' => $manager] = removerHarness();
		$manager->expects(test()->once())->method('disable')->with('acme/thing');

		$remover->remove('acme/thing');

		expect(is_dir($datadir . '/extensions/acme/thing'))->toBeFalse()
			->and($states->getState('acme/thing'))->toBeNull();
	});

	test('keeps extension settings and data', function (): void {
		['remover' => $remover, 'datadir' => $datadir] = removerHarness();
		mkdir($datadir . '/.system/extension-settings/acme', 0755, true);
		file_put_contents($datadir . '/.system/extension-settings/acme/thing.json', '{}');
		mkdir($datadir . '/.system/extension-data/acme/thing', 0755, true);
		file_put_contents($datadir . '/.system/extension-data/acme/thing/state.json', '{}');

		$remover->remove('acme/thing');

		expect(is_file($datadir . '/.system/extension-settings/acme/thing.json'))->toBeTrue()
			->and(is_file($datadir . '/.system/extension-data/acme/thing/state.json'))->toBeTrue();
	});

	test('reports an unknown id as not found', function (): void {
		['remover' => $remover] = removerHarness();

		expect(fn () => $remover->remove('acme/nothing'))
			->toThrow(InstallException::class, 'not found');
	});

	test('refuses ids that are not a well-formed vendor/name and deletes nothing', function (string $id): void {
		['remover' => $remover, 'datadir' => $datadir, 'manager' => $manager] = removerHarness();
		$manager->expects(test()->never())->method('disable');
		// Something above the extensions directory that `../..` would reach.
		file_put_contents($datadir . '/.system/sentinel.txt', 'keep');

		expect(fn () => $remover->remove($id))->toThrow(InstallException::class, 'not found');
		expect(fn () => $remover->assertRemovable($id))->toThrow(InstallException::class, 'not found');

		expect(is_dir($datadir))->toBeTrue()
			->and(is_file($datadir . '/.system/sentinel.txt'))->toBeTrue()
			->and(is_dir($datadir . '/extensions'))->toBeTrue()
			->and(is_dir($datadir . '/extensions/acme'))->toBeTrue()
			->and(is_file($datadir . '/extensions/acme/thing/extension.json'))->toBeTrue();
	})->with([
		'parent traversal'   => ['../..'],
		'vendor traversal'   => ['acme/..'],
		'dot name'           => ['acme/.'],
		'uppercase'          => ['ACME/Thing'],
		'trailing newline'   => ["acme/thing\n"],
		'three segments'     => ['acme/thing/x'],
	]);

	test('refuses a directory with no manifest and leaves it in place', function (): void {
		['remover' => $remover, 'datadir' => $datadir, 'manager' => $manager] = removerHarness();
		$manager->expects(test()->never())->method('disable');
		mkdir($datadir . '/extensions/acme/bare', 0755, true);
		file_put_contents($datadir . '/extensions/acme/bare/keep.txt', 'keep');

		expect(fn () => $remover->remove('acme/bare'))->toThrow(InstallException::class, 'not found');
		expect(fn () => $remover->assertRemovable('acme/bare'))->toThrow(InstallException::class, 'not found');

		expect(is_file($datadir . '/extensions/acme/bare/keep.txt'))->toBeTrue();
	});

	test('refuses a bundled extension and leaves it in place', function (): void {
		$bundled = tcmsTestTempDir('tcms-ext-remove-bundled');
		mkdir($bundled . '/acme/core', 0755, true);
		file_put_contents($bundled . '/acme/core/extension.json', testExtensionManifest('acme/core'));
		file_put_contents($bundled . '/acme/core/Extension.php', testExtensionClass());
		['remover' => $remover] = removerHarness(bundledDir: $bundled);

		expect(fn () => $remover->remove('acme/core'))
			->toThrow(InstallException::class, 'bundled');
		expect(is_file($bundled . '/acme/core/extension.json'))->toBeTrue();
	});
});
