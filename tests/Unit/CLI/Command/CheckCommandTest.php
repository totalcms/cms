<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use DI\Container;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CheckCommand;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SecurityAuditor;
use TotalCMS\Domain\Security\Service\SecurityReportStore;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\TotalCMS;

require_once __DIR__ . '/helpers.php';

/**
 * @param array<string,mixed> $overrides  ServerChecker method => return value
 */
function checkCommandTester(object $test, array $overrides = [], ?SecurityReport $report = null, string $domain = 'example.com'): CommandTester
{
	$make = function (array $overrides, ?SecurityReport $report, string $domain): CommandTester {
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

		$auditor = $this->createMock(SecurityAuditor::class);
		$auditor->method('audit')->willReturn($report ?? new SecurityReport([
			SecurityFinding::ok('https', 'HTTPS in use'),
		], '2026-10-31T10:00:00+00:00'));

		$dir = sys_get_temp_dir() . '/tcms-checkcmd-' . bin2hex(random_bytes(4));
		mkdir($dir . '/.system', 0777, true);
		$store = new SecurityReportStore(new AtomicJsonStore(new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($dir))), $dir, new NullLogger()));

		$container = $this->createMock(Container::class);
		$container->method('get')->willReturnMap([
			[ServerChecker::class, $checker],
			[SecurityAuditor::class, $auditor],
			[SecurityReportStore::class, $store],
		]);

		$totalcms         = $this->createMock(TotalCMS::class);
		$totalcms->config = createTestConfig(['domain' => $domain]);
		$totalcms->method('container')->willReturn($container);

		$command = new CheckCommand($totalcms);
		(new Application())->addCommand($command);

		return new CommandTester($command);
	};

	return $make->call($test, $overrides, $report, $domain);
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

function securityReport(SecurityFinding ...$findings): SecurityReport
{
	return new SecurityReport(array_values($findings), '2026-10-31T10:00:00+00:00');
}

function criticalFinding(): SecurityFinding
{
	return SecurityFinding::found('data-folder-public', Severity::Critical, 'Data folder is public', 'It answers over HTTP.', 'Deny access to tcms-data.', 'docs/security');
}

it('json includes the security findings', function (): void {
	$tester = checkCommandTester($this, [], securityReport(SecurityFinding::ok('https', 'HTTPS in use'), SecurityFinding::skipped('x', 'X', 'why')));
	$tester->execute(['--json' => true]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['security']['checked'])->toBe(2);
	expect($data['security']['findings'][0]['id'])->toBe('https');
	expect($tester->getStatusCode())->toBe(0);
});

it('a critical finding fails the exit code', function (): void {
	$tester = checkCommandTester($this, [], securityReport(criticalFinding()));
	$tester->execute(['--json' => true]);

	expect($tester->getStatusCode())->toBe(1);
	expect(json_decode($tester->getDisplay(), true)['ok'])->toBeFalse();
});

it('an ignored critical does not fail it', function (): void {
	$tester = checkCommandTester($this, [], securityReport(criticalFinding()->withIgnored()));
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(0);
	expect($tester->getDisplay())->toContain('(ignored)');
});

it('--no-security leaves the section out and keeps the old exit code', function (): void {
	$tester = checkCommandTester($this, [], securityReport(criticalFinding()));
	$tester->execute(['--no-security' => true, '--json' => true]);

	expect($tester->getStatusCode())->toBe(0);
	expect(json_decode($tester->getDisplay(), true))->not->toHaveKey('security');

	$tester->execute(['--no-security' => true]);
	expect($tester->getDisplay())->not->toContain('Data folder is public');
});

it('human output shows the fix under the finding', function (): void {
	$tester = checkCommandTester($this, [], securityReport(criticalFinding()));
	$tester->execute([]);

	$output = $tester->getDisplay();
	expect($output)->toContain('Security');
	expect($output)->toContain('✗ Data folder is public');
	expect($output)->toContain("\n    Deny access to tcms-data.");
	expect($output)->toContain('last recorded');
});

it('reports a null url in json and the fallback text in human output when the address is unknown', function (): void {
	$tester = checkCommandTester($this, [], securityReport(SecurityFinding::ok('https', 'HTTPS in use')), 'unknown');
	$tester->execute(['--json' => true]);
	expect(json_decode($tester->getDisplay(), true)['security']['url'])->toBeNull();

	$tester->execute([]);
	expect($tester->getDisplay())->toContain('visit the admin once or set domain');
});

it('prints a less-than sign in a finding title literally', function (): void {
	$finding = SecurityFinding::found('x', Severity::Warning, 'Template <script> prints raw data', 'd', 'Fix <b> it', 'docs');
	$skipped = SecurityFinding::skipped('y', 'Skipped check', 'Could not read <path>');
	$tester  = checkCommandTester($this, [], securityReport($finding, $skipped));
	$tester->execute([]);

	expect($tester->getDisplay())->toContain('Template <script> prints raw data')->toContain('Fix <b> it')->toContain('Could not read <path>');
});

it('--no-security omits the recorded-address note', function (): void {
	$tester = checkCommandTester($this);
	$tester->execute(['--no-security' => true]);

	expect($tester->getDisplay())->not->toContain('last recorded');
});

it('human output prints the detail of a found finding under its title, before the fix', function (): void {
	$tester = checkCommandTester($this, [], securityReport(criticalFinding()));
	$tester->execute([]);

	$output = $tester->getDisplay();
	expect($output)->toContain("✗ Data folder is public\n    It answers over HTTP.\n    Deny access to tcms-data.");
});

it('human output indents every line of a multi-line detail and fix', function (): void {
	$finding = SecurityFinding::found('x', Severity::Warning, 'Headers', "line one\nline <two>", "Add these:\n\nHeader set A\nHeader set B", 'docs');
	$tester  = checkCommandTester($this, [], securityReport($finding));
	$tester->execute([]);

	$output = $tester->getDisplay();
	expect($output)->toContain("    line one\n    line <two>\n")
		->and($output)->toContain("    Add these:\n\n    Header set A\n    Header set B\n");
});
