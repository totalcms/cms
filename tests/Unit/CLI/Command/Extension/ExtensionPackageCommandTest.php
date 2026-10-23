<?php

declare(strict_types=1);

use DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Extension\ExtensionPackageCommand;
use TotalCMS\Domain\Extension\Service\Install\ExtensionPackager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\TotalCMS;

function packageCommandTester(): CommandTester
{
	$container = new Container([
		ExtensionPackager::class => new ExtensionPackager(new ManifestValidator(test()->createMock(EditionFeatureService::class))),
	]);
	$totalcms = test()->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);

	$command = new ExtensionPackageCommand($totalcms);
	(new Application())->addCommand($command);

	return new CommandTester($command);
}

function packageCommandFixture(): string
{
	$dir = tcmsTestTempDir('tcms-ext-packcmd');
	mkdir($dir . '/thing', 0755, true);
	file_put_contents($dir . '/thing/extension.json', testExtensionManifest('acme/thing', '2.0.0'));
	file_put_contents($dir . '/thing/Extension.php', testExtensionClass());

	return $dir . '/thing';
}

afterEach(function (): void {
	foreach (glob(sys_get_temp_dir() . '/tcms-ext-packcmd-*') ?: [] as $dir) {
		recursiveDelete($dir);
	}
});

describe('extension:package command', function (): void {
	test('packages the given directory and prints the zip path', function (): void {
		$dir    = packageCommandFixture();
		$tester = packageCommandTester();

		$tester->execute(['dir' => $dir]);

		expect($tester->getStatusCode())->toBe(0)
			->and($tester->getDisplay())->toContain('thing-2.0.0.zip')
			->and(is_file(dirname($dir) . '/thing-2.0.0.zip'))->toBeTrue();
	});

	test('honors --output and --json', function (): void {
		$dir = packageCommandFixture();
		$out = $dir . '-out';
		mkdir($out, 0755, true);
		$tester = packageCommandTester();

		$tester->execute(['dir' => $dir, '--output' => $out, '--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['zipPath'])->toBe($out . '/thing-2.0.0.zip')
			->and($data['fileCount'])->toBe(2);
	});

	test('fails with the reason when the directory has no manifest', function (): void {
		$dir = tcmsTestTempDir('tcms-ext-packcmd-empty');
		mkdir($dir, 0755, true);
		$tester = packageCommandTester();

		$tester->execute(['dir' => $dir]);

		expect($tester->getStatusCode())->toBe(1)
			->and($tester->getDisplay())->toContain('extension.json');
	});
});
