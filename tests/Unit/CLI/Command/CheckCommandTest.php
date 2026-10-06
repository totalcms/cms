<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CheckCommand;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\TotalCMS;

require_once __DIR__ . '/helpers.php';

/**
 * @param array<string,mixed> $overrides  ServerChecker method => return value
 */
function checkCommandTester(object $test, array $overrides = []): CommandTester
{
	$make = function (array $overrides): CommandTester {
		$results = array_merge([
			'bundleCheck'                => true,
			'licenseInfo'                => ['License Status' => 'Valid', 'License Edition' => 'Pro', 'Licensed Domain' => 'example.com'],
			'serverInfo'                 => ['Total CMS Version' => '3.6.2', 'PHP Version' => '8.3.0', 'Memory Limit' => '256M', 'License Status' => 'Valid', 'License Edition' => 'Pro', 'Licensed Domain' => 'example.com'],
			'checkPermissions'           => ['tcms-data' => true, 'cache' => true, 'logs' => true, 'tmp' => true],
			'checkRequiredSoftware'      => ['PHP 8.2.0+' => true, 'PHP Extension: gd' => true],
			'getOptionalSoftwareDetails' => [
				'intl'    => ['name' => 'PHP Extension: intl', 'available' => true, 'description' => '', 'recommendation' => 'Recommended for multilingual sites', 'performance_impact' => ''],
				'imagick' => ['name' => 'PHP Extension: imagick', 'available' => false, 'description' => '', 'recommendation' => 'Recommended for heavy image processing', 'performance_impact' => ''],
			],
			'getConfig'                  => ['datadir' => '/srv/tcms-data', 'smtp' => ['password' => '********']],
		], $overrides);

		$checker = $this->createMock(ServerChecker::class);
		foreach ($results as $method => $value) {
			$checker->method($method)->willReturn($value);
		}

		$container = $this->createMock(Container::class);
		$container->method('get')->with(ServerChecker::class)->willReturn($checker);

		$totalcms         = $this->createMock(TotalCMS::class);
		$totalcms->config = createTestConfig(['domain' => 'example.com']);
		$totalcms->method('container')->willReturn($container);

		$command = new CheckCommand($totalcms);
		(new Application())->addCommand($command);

		return new CommandTester($command);
	};

	return $make->call($test, $overrides);
}

it('passes when integrity, permissions and required software are all fine', function (): void {
	$tester = checkCommandTester($this);
	$tester->execute([]);

	$output = $tester->getDisplay();
	expect($tester->getStatusCode())->toBe(0);
	expect($output)->toContain('Integrity');
	expect($output)->toContain('License');
	expect($output)->toContain('Pro');
	expect($output)->toContain('Permissions');
	expect($output)->toContain('tcms-data');
	expect($output)->toContain('Required software');
	expect($output)->toContain('PHP Extension: gd');
	expect($output)->toContain('Optional software');
	expect($output)->toContain('imagick');
	expect($output)->toContain('Recommended for heavy image processing');
	expect($output)->not->toContain('Recommended for multilingual sites');
	expect($output)->not->toContain('/srv/tcms-data');
});

it('says the values are the command line PHP, not the web server', function (): void {
	$tester = checkCommandTester($this);
	$tester->execute([]);

	expect($tester->getDisplay())->toContain('Server Checker');
	expect($tester->getDisplay())->toContain(PHP_SAPI);
});

it('fails when a required extension is missing', function (): void {
	$tester = checkCommandTester($this, ['checkRequiredSoftware' => ['PHP 8.2.0+' => true, 'PHP Extension: gd' => false]]);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('PHP Extension: gd');
	expect($tester->getDisplay())->toContain('missing');
});

it('fails when the bundle integrity check fails', function (): void {
	$tester = checkCommandTester($this, ['bundleCheck' => false]);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('corrupted');
});

it('fails when a directory is not writable', function (): void {
	$tester = checkCommandTester($this, ['checkPermissions' => ['tcms-data' => true, 'cache' => false]]);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('not writable');
});

it('shows the redacted config only with --config', function (): void {
	$tester = checkCommandTester($this);
	$tester->execute(['--config' => true]);

	expect($tester->getDisplay())->toContain('/srv/tcms-data');
	expect($tester->getDisplay())->toContain('********');
});

it('outputs every section as JSON with an overall ok flag', function (): void {
	$tester = checkCommandTester($this, ['checkRequiredSoftware' => ['PHP Extension: gd' => false]]);
	$tester->execute(['--json' => true, '--config' => true]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['ok'])->toBeFalse();
	expect($data['integrity'])->toBeTrue();
	expect($data['php']['sapi'])->toBe(PHP_SAPI);
	expect($data['license']['License Edition'])->toBe('Pro');
	expect($data['server'])->toBe(['Total CMS Version' => '3.6.2', 'PHP Version' => '8.3.0', 'Memory Limit' => '256M']);
	expect($data['permissions']['cache'])->toBeTrue();
	expect($data['required']['PHP Extension: gd'])->toBeFalse();
	expect($data['optional']['imagick']['available'])->toBeFalse();
	expect($data['config']['datadir'])->toBe('/srv/tcms-data');
	expect($tester->getStatusCode())->toBe(1);
});

it('leaves config out of the JSON without --config', function (): void {
	$tester = checkCommandTester($this);
	$tester->execute(['--json' => true]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data)->not->toHaveKey('config');
	expect($data['ok'])->toBeTrue();
});
