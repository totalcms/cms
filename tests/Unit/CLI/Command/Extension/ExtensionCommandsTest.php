<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command\Extension;

use DI\Container;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Extension\ExtensionDisableCommand;
use TotalCMS\CLI\Command\Extension\ExtensionEnableCommand;
use TotalCMS\CLI\Command\Extension\ExtensionListCommand;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

function createTestDependencies(): array
{
	$fixturesDir = dirname(__DIR__, 4) . '/fixtures';

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(true);
	$storage->method('read')->willReturn(json_encode([
		'test-vendor/hello-world' => ['enabled' => true, 'installed_at' => '2026-01-01', 'version' => '1.0.0', 'error' => null],
		'test-vendor/broken-ext'  => ['enabled' => false, 'installed_at' => '2026-01-01', 'version' => '1.0.0', 'error' => null],
	]));
	$storage->method('write')->willReturn(true);
	$stateRepo = new ExtensionStateRepository($storage);

	$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $fixturesDir;

	$discovery = new ExtensionDiscovery($config, new ManifestValidator(), new NullLogger());

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$mockContainer = test()->createMock(\Psr\Container\ContainerInterface::class);
	$mockContainer->method('has')->willReturn(false);

	$manager = new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$mockContainer,
		new NullLogger(),
	);

	return [
		'discovery'  => $discovery,
		'stateRepo'  => $stateRepo,
		'manager'    => $manager,
	];
}

function createMockTotalCMS(array $deps): TotalCMS
{
	$container = new Container([
		ExtensionDiscovery::class       => $deps['discovery'],
		ExtensionStateRepository::class => $deps['stateRepo'],
		ExtensionManager::class         => $deps['manager'],
	]);

	$totalcms = test()->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);

	return $totalcms;
}

describe('extension:list command', function (): void {
	test('lists discovered extensions', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionListCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute([]);

		$output = $tester->getDisplay();
		expect($output)->toContain('test-vendor/hello-world');
		expect($output)->toContain('test-vendor/broken-ext');
		expect($output)->toContain('enabled');
		expect($output)->toContain('disabled');
		expect($tester->getStatusCode())->toBe(0);
	});

	test('outputs JSON with --json flag', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionListCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data)->toBeArray();
		expect($data)->toHaveCount(2);

		$ids = array_column($data, 'id');
		expect($ids)->toContain('test-vendor/hello-world');
		expect($ids)->toContain('test-vendor/broken-ext');
	});
});

describe('extension:enable command', function (): void {
	test('enables an extension', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionEnableCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['id' => 'test-vendor/broken-ext']);

		$output = $tester->getDisplay();
		expect($output)->toContain('enabled');
		expect($tester->getStatusCode())->toBe(0);
	});

	test('reports error for unknown extension', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionEnableCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['id' => 'nonexistent/extension']);

		expect($tester->getStatusCode())->toBe(1);
	});

	test('outputs JSON with --json flag', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionEnableCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['id' => 'test-vendor/hello-world', '--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['status'])->toBe('enabled');
		expect($data['id'])->toBe('test-vendor/hello-world');
	});
});

describe('extension:disable command', function (): void {
	test('disables an extension', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionDisableCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['id' => 'test-vendor/hello-world']);

		$output = $tester->getDisplay();
		expect($output)->toContain('disabled');
		expect($tester->getStatusCode())->toBe(0);
	});

	test('outputs JSON with --json flag', function (): void {
		$deps     = createTestDependencies();
		$totalcms = createMockTotalCMS($deps);

		$app     = new Application();
		$command = new ExtensionDisableCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['id' => 'test-vendor/hello-world', '--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['status'])->toBe('disabled');
	});
});
