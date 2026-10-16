<?php

declare(strict_types=1);

use DI\Container;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Extension\ExtensionInstallCommand;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\Install\ExtensionArchive;
use TotalCMS\Domain\Extension\Service\Install\ExtensionInstaller;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

/** @return array{tester: CommandTester, datadir: string} */
function installCommandHarness(): array
{
	$datadir = tcmsTestTempDir('tcms-ext-installcmd');
	mkdir($datadir . '/.system', 0755, true);
	mkdir($datadir . '/extensions', 0755, true);
	mkdir($datadir . '/tmp', 0755, true);

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $datadir;
	$config->tmpdir  = $datadir . '/tmp';

	$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$discovery = new ExtensionDiscovery($config, $validator, new NullLogger(), $datadir . '/no-project', $datadir . '/no-bundled', static fn (): array => []);
	$states    = new ExtensionStateRepository(new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($datadir))));
	$installer = new ExtensionInstaller(new ExtensionArchive($validator), $discovery, $states, $config, new NullLogger());

	$totalcms = test()->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn(new Container([ExtensionInstaller::class => $installer]));

	$command = new ExtensionInstallCommand($totalcms);
	(new Application())->addCommand($command);

	return ['tester' => new CommandTester($command), 'datadir' => $datadir];
}

function installCommandZip(string $version = '1.0.0'): string
{
	return buildTestZip([
		'thing/extension.json' => testExtensionManifest('acme/thing', $version),
		'thing/Extension.php'  => testExtensionClass(),
	]);
}

afterEach(function (): void {
	foreach (glob(sys_get_temp_dir() . '/tcms-ext-installcmd-*') ?: [] as $dir) {
		recursiveDelete($dir);
	}
});

describe('extension:install command', function (): void {
	test('installs a fresh extension and names the enable command', function (): void {
		['tester' => $tester, 'datadir' => $datadir] = installCommandHarness();

		$tester->execute(['zip' => installCommandZip()]);

		expect($tester->getStatusCode())->toBe(0)
			->and($tester->getDisplay())->toContain('Installed acme/thing 1.0.0')
			->and($tester->getDisplay())->toContain('tcms extension:enable acme/thing')
			->and(is_file($datadir . '/extensions/acme/thing/extension.json'))->toBeTrue();
	});

	test('an update refuses to run non-interactively without --force', function (): void {
		['tester' => $tester, 'datadir' => $datadir] = installCommandHarness();
		$tester->execute(['zip' => installCommandZip('1.0.0')]);

		$tester->execute(['zip' => installCommandZip('1.1.0')], ['interactive' => false]);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('--force')
			->and(file_get_contents($datadir . '/extensions/acme/thing/extension.json'))->toContain('"1.0.0"');
	});

	test('an update with --force replaces and reports the previous version', function (): void {
		['tester' => $tester] = installCommandHarness();
		$tester->execute(['zip' => installCommandZip('1.0.0')]);

		$tester->execute(['zip' => installCommandZip('1.1.0'), '--force' => true]);

		expect($tester->getStatusCode())->toBe(0)
			->and($tester->getDisplay())->toContain('Updated acme/thing 1.0.0 → 1.1.0');
	});

	test('names the id, name, version and fresh install before installing', function (): void {
		['tester' => $tester] = installCommandHarness();

		$tester->execute(['zip' => installCommandZip('1.0.0')]);

		$display = $tester->getDisplay();
		expect($display)->toContain('Extension: acme/thing')
			->and($display)->toContain('Version:   1.0.0')
			->and($display)->toContain('fresh install')
			->and(strpos($display, 'fresh install'))->toBeLessThan(strpos($display, 'Installed acme/thing'));
	});

	test('names the installed version before asking to replace it', function (): void {
		['tester' => $tester] = installCommandHarness();
		$tester->execute(['zip' => installCommandZip('1.0.0')]);

		$tester->setInputs(['n']);
		$tester->execute(['zip' => installCommandZip('1.1.0')], ['interactive' => true]);

		$display = $tester->getDisplay();
		expect($display)->toContain('Version:   1.1.0')
			->and($display)->toContain('update of installed version 1.0.0')
			->and(strpos($display, 'update of installed version'))->toBeLessThan(strpos($display, 'Replace acme/thing'));
	});

	test('--json emits the install result', function (): void {
		['tester' => $tester] = installCommandHarness();

		$tester->execute(['zip' => installCommandZip(), '--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['status'])->toBe('installed')
			->and($data['id'])->toBe('acme/thing');
	});

	test('a missing file fails with a message', function (): void {
		['tester' => $tester] = installCommandHarness();

		$tester->execute(['zip' => '/nowhere/thing.zip']);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('not found');
	});

	test('a bad zip fails with the archive reason', function (): void {
		['tester' => $tester, 'datadir' => $datadir] = installCommandHarness();
		file_put_contents($datadir . '/bad.zip', 'nope');

		$tester->execute(['zip' => $datadir . '/bad.zip']);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('not a zip');
	});
});
