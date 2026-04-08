<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Update\Service\MaintenanceMode;
use TotalCMS\Support\Config;

use function Tests\Unit\CLI\Command\createTestConfig;

require_once __DIR__ . '/../../CLI/Command/helpers.php';

final class MaintenanceModeTest extends TestCase
{
	private MaintenanceMode $maintenance;
	private string $tmpDir;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-maintenance-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);

		$config            = createTestConfig(['cachedir' => $this->tmpDir]);
		$this->maintenance = new MaintenanceMode($config);
	}

	protected function tearDown(): void
	{
		$flagFile = $this->tmpDir . '/maintenance.flag';
		if (file_exists($flagFile)) {
			unlink($flagFile);
		}
		@rmdir($this->tmpDir);
	}

	public function testNotEnabledByDefault(): void
	{
		expect($this->maintenance->isEnabled())->toBeFalse();
	}

	public function testEnableCreatesFlagFile(): void
	{
		$this->maintenance->enable();

		expect($this->maintenance->isEnabled())->toBeTrue();
		expect(file_exists($this->tmpDir . '/maintenance.flag'))->toBeTrue();
	}

	public function testDisableRemovesFlagFile(): void
	{
		$this->maintenance->enable();
		$this->maintenance->disable();

		expect($this->maintenance->isEnabled())->toBeFalse();
		expect(file_exists($this->tmpDir . '/maintenance.flag'))->toBeFalse();
	}

	public function testDisableWhenNotEnabledDoesNothing(): void
	{
		$this->maintenance->disable();

		expect($this->maintenance->isEnabled())->toBeFalse();
	}
}
