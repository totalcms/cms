<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\JobQueue\Data\JobQueueHealthData;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Domain\License\Data\LicenseStatusData;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Domain\Update\Service\UpdateChecker;

/**
 * Unit tests for AdminTwigAdapter::dashboardAlerts().
 *
 * The adapter is a large readonly class; we build it without the constructor
 * and inject only the collaborators the methods under test actually use —
 * the same pattern used by AdminTwigAdapterSettingsSectionsTest.
 *
 * AutomationRunReader and ExtensionStateRepository are declared `final`, so we
 * use hand-rolled stubs rather than createMock().
 */
final class AdminTwigAdapterDashboardAlertsTest extends TestCase
{
	// -------------------------------------------------------------------------
	// Value-object helpers
	// -------------------------------------------------------------------------

	private function noUpdateInfo(): UpdateInfo
	{
		return new UpdateInfo(
			available: false,
			version: '3.5.0',
			releaseDate: '',
			severity: '',
			changelog: '',
			buildHash: '',
			downloadUrl: '',
		);
	}

	private function healthyQueueData(): JobQueueHealthData
	{
		return new JobQueueHealthData(stalled: false, pendingCount: 0, oldestAgeMinutes: 0, thresholdMinutes: 30);
	}

	// -------------------------------------------------------------------------
	// Stub factories for final classes
	// -------------------------------------------------------------------------

	/**
	 * Build a real AutomationRunReader backed by a hand-rolled storage stub.
	 *
	 * AutomationRunReader is final readonly — it cannot be mocked. We satisfy it
	 * by constructing a StorageAdapterInterface stub that returns our canned run
	 * records from the paths the reader's latestPerAutomation() method traverses.
	 *
	 * @param array<string,array<string,mixed>> $latest  keyed by automation id
	 */
	private function stubRunReader(array $latest): AutomationRunReader
	{
		$filesystem = new class($latest) implements StorageAdapterInterface {
			/** @param array<string,array<string,mixed>> $latest */
			public function __construct(private readonly array $latest)
			{
			}

			public function directoryExists(string $location): bool
			{
				if ($location === '.system/automations') {
					return $this->latest !== [];
				}

				// .system/automations/{id}/runs
				$parts = explode('/', $location);

				return count($parts) === 4
					&& $parts[0] === '.system'
					&& $parts[1] === 'automations'
					&& $parts[3] === 'runs'
					&& isset($this->latest[$parts[2]]);
			}

			public function listDirectories(string $directory): array
			{
				if ($directory !== '.system/automations') {
					return [];
				}

				return array_map(
					static fn (string $id): string => '.system/automations/' . $id,
					array_keys($this->latest),
				);
			}

			public function listFiles(string $directory): array
			{
				$parts = explode('/', $directory);
				// .system/automations/{id}/runs
				if (count($parts) === 4 && isset($this->latest[$parts[2]])) {
					return [$directory . '/run-001.json'];
				}

				return [];
			}

			public function read(string $location): string
			{
				$parts = explode('/', $location);
				// .system/automations/{id}/runs/run-001.json
				if (count($parts) === 5 && isset($this->latest[$parts[2]])) {
					return (string)json_encode($this->latest[$parts[2]]);
				}

				return '{}';
			}

			public function fileExists(string $location): bool
			{
				return false;
			}

			public function write(string $location, string $contents): bool
			{
				return true;
			}

			public function import(string $import, string $dest): bool
			{
				return true;
			}

			public function move(string $old, string $new): bool
			{
				return true;
			}

			public function copyDirectory(string $old, string $new): bool
			{
				return true;
			}

			public function delete(string $location): bool
			{
				return true;
			}

			public function deleteDirectory(string $location): bool
			{
				return true;
			}

			public function mimeType(string $location): string
			{
				return '';
			}

			public function fileSize(string $location): int
			{
				return 0;
			}

			public function readStream(string $location)
			{
				return fopen('php://memory', 'r');
			}

			public function flysystem(): FilesystemOperator
			{
				throw new \LogicException('not needed in tests');
			}
		};

		return new AutomationRunReader($filesystem);
	}

	/**
	 * Build a real ExtensionStateRepository with a pre-loaded cache.
	 *
	 * ExtensionStateRepository is final — we build it without the constructor
	 * and inject the internal cache directly via reflection.
	 *
	 * @param array<string,array<string,mixed>> $rawStates  keyed by extension id
	 */
	private function stubStateRepo(array $rawStates): ExtensionStateRepository
	{
		$repo = (new \ReflectionClass(ExtensionStateRepository::class))->newInstanceWithoutConstructor();

		$states = [];
		foreach ($rawStates as $id => $data) {
			$states[$id] = ExtensionState::fromArray($data);
		}

		(new \ReflectionClass($repo))->getProperty('cache')->setValue($repo, $states);

		return $repo;
	}

	// -------------------------------------------------------------------------
	// Adapter factory
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function makeAlertsAdapter(array $overrides = []): AdminTwigAdapter
	{
		$updateChecker  = $overrides['updateChecker'] ?? $this->mockUpdateChecker($this->noUpdateInfo());
		$licenseStatus  = $overrides['licenseStatus'] ?? $this->mockLicenseStatus(new LicenseStatusData(showIcon: false));
		$jobQueueHealth = $overrides['jobQueueHealth'] ?? $this->mockJobQueueHealth($this->healthyQueueData());
		$runReader      = $overrides['runReader'] ?? $this->stubRunReader([]);
		$stateRepo      = $overrides['stateRepo'] ?? $this->stubStateRepo([]);

		$adapter = (new \ReflectionClass(AdminTwigAdapter::class))->newInstanceWithoutConstructor();
		$ref     = new \ReflectionClass($adapter);

		$ref->getProperty('updateChecker')->setValue($adapter, $updateChecker);
		$ref->getProperty('licenseStatus')->setValue($adapter, $licenseStatus);
		$ref->getProperty('jobQueueHealth')->setValue($adapter, $jobQueueHealth);
		$ref->getProperty('automationRunReader')->setValue($adapter, $runReader);
		$ref->getProperty('extensionStateRepository')->setValue($adapter, $stateRepo);

		return $adapter;
	}

	private function mockUpdateChecker(UpdateInfo $info): UpdateChecker
	{
		$mock = $this->createMock(UpdateChecker::class);
		$mock->method('checkForUpdate')->with(false)->willReturn($info);

		return $mock;
	}

	private function mockLicenseStatus(LicenseStatusData $data): LicenseStatus
	{
		$mock = $this->createMock(LicenseStatus::class);
		$mock->method('getSidebarStatus')->willReturn($data);

		return $mock;
	}

	private function mockJobQueueHealth(JobQueueHealthData $data): JobQueueHealth
	{
		$mock = $this->createMock(JobQueueHealth::class);
		$mock->method('status')->willReturn($data);

		return $mock;
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — all-clear
	// -------------------------------------------------------------------------

	public function testAllClearReturnsEmptyArray(): void
	{
		$this->assertSame([], $this->makeAlertsAdapter()->dashboardAlerts());
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — update available
	// -------------------------------------------------------------------------

	public function testUpdateAvailableEmitsWarningAlert(): void
	{
		$update = new UpdateInfo(
			available: true,
			version: '3.6.0',
			releaseDate: '2026-07-01',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '',
		);

		$alerts = $this->makeAlertsAdapter(['updateChecker' => $this->mockUpdateChecker($update)])->dashboardAlerts();
		$this->assertNotEmpty($alerts);
		$this->assertContains('warning', array_column($alerts, 'level'));

		$found = array_filter(array_column($alerts, 'message'), static fn (string $m): bool => str_contains($m, '3.6.0'));
		$this->assertNotEmpty($found, 'Alert message must contain the new version number.');
	}

	public function testNoUpdateAvailableEmitsNoAlert(): void
	{
		$this->assertSame([], $this->makeAlertsAdapter()->dashboardAlerts());
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — license status
	// -------------------------------------------------------------------------

	public function testLicenseInvalidEmitsErrorAlert(): void
	{
		$status  = new LicenseStatusData(showIcon: true, severity: 'error', tooltip: 'License validation failed');
		$adapter = $this->makeAlertsAdapter(['licenseStatus' => $this->mockLicenseStatus($status)]);

		$this->assertContains('error', array_column($adapter->dashboardAlerts(), 'level'));
	}

	public function testTrialExpiringEmitsAlert(): void
	{
		$status  = new LicenseStatusData(showIcon: true, severity: 'warning', daysRemaining: 5, tooltip: 'Trial expires in 5 days');
		$adapter = $this->makeAlertsAdapter(['licenseStatus' => $this->mockLicenseStatus($status)]);
		$alerts  = $adapter->dashboardAlerts();

		$this->assertNotEmpty($alerts);
		$levels = array_column($alerts, 'level');
		$this->assertTrue(
			in_array('warning', $levels, true) || in_array('error', $levels, true),
			'Expected a warning or error alert for trial expiry.',
		);
	}

	public function testLicenseShowIconFalseEmitsNoAlert(): void
	{
		$status  = new LicenseStatusData(showIcon: false);
		$adapter = $this->makeAlertsAdapter(['licenseStatus' => $this->mockLicenseStatus($status)]);

		$this->assertSame([], $adapter->dashboardAlerts(), 'No alerts when showIcon is false.');
	}

	public function testUnregisteredTrialEmitsInfoAlert(): void
	{
		$mock = $this->createMock(LicenseStatus::class);
		$mock->method('getSidebarStatus')->willReturn(new LicenseStatusData(showIcon: false));
		$mock->method('isUnregisteredTrial')->willReturn(true);

		$adapter = $this->makeAlertsAdapter(['licenseStatus' => $mock]);
		$alerts  = $adapter->dashboardAlerts();

		$this->assertNotEmpty($alerts);
		$this->assertContains('info', array_column($alerts, 'level'));
		$this->assertContains('Register your trial to get email reminders before it expires.', array_column($alerts, 'message'));
	}

	public function testRegisteredTrialEmitsNoUnregisteredTrialAlert(): void
	{
		$mock = $this->createMock(LicenseStatus::class);
		$mock->method('getSidebarStatus')->willReturn(new LicenseStatusData(showIcon: false));
		$mock->method('isUnregisteredTrial')->willReturn(false);

		$adapter = $this->makeAlertsAdapter(['licenseStatus' => $mock]);

		$this->assertSame([], $adapter->dashboardAlerts());
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — failed automations
	// -------------------------------------------------------------------------

	public function testFailedAutomationsEmitsWarningAlert(): void
	{
		$latest = [
			'send-digest'  => ['status' => 'failed',  'runAt' => time()],
			'clean-images' => ['status' => 'success', 'runAt' => time()],
		];

		$adapter = $this->makeAlertsAdapter(['runReader' => $this->stubRunReader($latest)]);
		$alerts  = $adapter->dashboardAlerts();

		$this->assertNotEmpty($alerts);
		$this->assertContains('warning', array_column($alerts, 'level'));
		$this->assertStringContainsString('1', implode(' ', array_column($alerts, 'message')));
	}

	public function testNoFailedAutomationsEmitsNoAlert(): void
	{
		$latest  = ['send-digest' => ['status' => 'success', 'runAt' => time()]];
		$adapter = $this->makeAlertsAdapter(['runReader' => $this->stubRunReader($latest)]);

		$this->assertSame([], $adapter->dashboardAlerts());
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — job queue stalled
	// -------------------------------------------------------------------------

	public function testJobQueueStalledEmitsWarningAlert(): void
	{
		$data    = new JobQueueHealthData(stalled: true, pendingCount: 3, oldestAgeMinutes: 45, thresholdMinutes: 30);
		$adapter = $this->makeAlertsAdapter(['jobQueueHealth' => $this->mockJobQueueHealth($data)]);
		$alerts  = $adapter->dashboardAlerts();

		$this->assertNotEmpty($alerts);
		$this->assertContains('warning', array_column($alerts, 'level'));
	}

	public function testJobQueueNotStalledEmitsNoAlert(): void
	{
		$this->assertSame([], $this->makeAlertsAdapter()->dashboardAlerts());
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — extension boot failures
	// -------------------------------------------------------------------------

	public function testEnabledExtensionWithErrorEmitsWarningAlert(): void
	{
		$states = [
			'vendor/good' => ['enabled' => true,  'error' => null],
			'vendor/bad'  => ['enabled' => true,  'error' => 'boot() failed: Something went wrong'],
		];

		$adapter = $this->makeAlertsAdapter(['stateRepo' => $this->stubStateRepo($states)]);
		$alerts  = $adapter->dashboardAlerts();

		$this->assertNotEmpty($alerts);
		$this->assertContains('warning', array_column($alerts, 'level'));
	}

	public function testDisabledExtensionWithErrorEmitsNoAlert(): void
	{
		$states  = ['vendor/disabled-bad' => ['enabled' => false, 'error' => 'register() failed: crash']];
		$adapter = $this->makeAlertsAdapter(['stateRepo' => $this->stubStateRepo($states)]);

		$this->assertSame([], $adapter->dashboardAlerts(), 'Disabled extensions with errors must not produce alerts.');
	}

	public function testEnabledExtensionsWithNoErrorsEmitNoAlert(): void
	{
		$states = [
			'vendor/ext-a' => ['enabled' => true, 'error' => null],
			'vendor/ext-b' => ['enabled' => true, 'error' => null],
		];

		$adapter = $this->makeAlertsAdapter(['stateRepo' => $this->stubStateRepo($states)]);

		$this->assertSame([], $adapter->dashboardAlerts());
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — return shape conformance
	// -------------------------------------------------------------------------

	public function testAlertShapeIsCorrect(): void
	{
		$update = new UpdateInfo(
			available: true,
			version: '3.6.0',
			releaseDate: '',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '',
		);

		$adapter = $this->makeAlertsAdapter(['updateChecker' => $this->mockUpdateChecker($update)]);

		foreach ($adapter->dashboardAlerts() as $alert) {
			$this->assertArrayHasKey('level', $alert);
			$this->assertArrayHasKey('message', $alert);
			$this->assertArrayHasKey('link', $alert);
			$this->assertArrayHasKey('linkText', $alert);
			$this->assertContains($alert['level'], ['warning', 'error', 'info']);
			$this->assertIsString($alert['message']);
			$this->assertTrue($alert['link'] === null || is_string($alert['link']));
			$this->assertTrue($alert['linkText'] === null || is_string($alert['linkText']));
		}
	}

	// -------------------------------------------------------------------------
	// dashboardAlerts() — multiple conditions produce multiple alerts
	// -------------------------------------------------------------------------

	public function testMultipleConditionsProduceMultipleAlerts(): void
	{
		$update  = new UpdateInfo(available: true, version: '3.6.0', releaseDate: '', severity: 'minor', changelog: '', buildHash: '', downloadUrl: '');
		$license = new LicenseStatusData(showIcon: true, severity: 'error', tooltip: 'License invalid');
		$queue   = new JobQueueHealthData(stalled: true, pendingCount: 2, oldestAgeMinutes: 60, thresholdMinutes: 30);
		$runs    = ['auto-a' => ['status' => 'failed', 'runAt' => time()]];
		$states  = ['vendor/bad' => ['enabled' => true, 'error' => 'boot() failed: crash']];

		$adapter = $this->makeAlertsAdapter([
			'updateChecker'  => $this->mockUpdateChecker($update),
			'licenseStatus'  => $this->mockLicenseStatus($license),
			'jobQueueHealth' => $this->mockJobQueueHealth($queue),
			'runReader'      => $this->stubRunReader($runs),
			'stateRepo'      => $this->stubStateRepo($states),
		]);

		$this->assertGreaterThanOrEqual(
			4,
			count($adapter->dashboardAlerts()),
			'Expected at least 4 alerts: update + license + queue + extension.',
		);
	}
}
