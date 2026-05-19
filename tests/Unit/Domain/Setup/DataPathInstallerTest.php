<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Setup;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Services\DataDirectoryManager;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Domain\Setup\Service\DataPathInstaller;
use TotalCMS\Support\Config;

use function Tests\Unit\CLI\Command\createTestConfig;

require_once __DIR__ . '/../../CLI/Command/helpers.php';

final class DataPathInstallerTest extends TestCase
{
	private string $sandbox;
	private string $docroot;
	private Config $config;
	private DataPathInstaller $installer;

	/** @var \PHPUnit\Framework\MockObject\MockObject&InstallationSettingsSaver */
	private \PHPUnit\Framework\MockObject\MockObject $settingsSaver;

	/** @var \PHPUnit\Framework\MockObject\MockObject&CacheManager */
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;

	protected function setUp(): void
	{
		$this->sandbox = sys_get_temp_dir() . '/tcms-installer-' . uniqid();
		mkdir($this->sandbox . '/public', 0755, true);
		$this->docroot = $this->sandbox . '/public';

		$this->config        = createTestConfig(['datadir' => $this->docroot . '/tcms-data', 'auth' => ['collection' => 'auth']]);
		$this->settingsSaver = $this->createMock(InstallationSettingsSaver::class);
		$this->cacheManager  = $this->createMock(CacheManager::class);

		$this->installer = new DataPathInstaller(
			new DataDirectoryManager(),
			$this->settingsSaver,
			$this->cacheManager,
			$this->config,
		);
	}

	protected function tearDown(): void
	{
		removeRecursive($this->sandbox);
	}

	public function testInstallsAboveDocrootWhenLocationIsDefault(): void
	{
		$this->cacheManager->expects($this->once())->method('clearAllCaches');
		$this->settingsSaver->expects($this->never())->method('saveSettings');

		$result = $this->installer->install('default', '', $this->docroot, 'en_US');

		$expected = $this->sandbox . '/tcms-data';
		$this->assertSame($expected, $result);
		$this->assertDirectoryExists($expected);
		$this->assertSame($expected, $this->config->datadir, 'Config::datadir must be synced to the chosen path.');
		$this->assertFileExists($expected . '/.system/settings.json');
	}

	public function testThrowsWhenLocationIsEmpty(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->installer->install('', '', $this->docroot, 'en_US');
	}

	public function testCustomLocationPersistsToInstallationSettings(): void
	{
		$customPath = $this->sandbox . '/elsewhere/tcms-data';
		mkdir(dirname($customPath), 0755, true);

		$this->settingsSaver->expects($this->once())
			->method('saveSettings')
			->with(['datadir' => $customPath]);

		$result = $this->installer->install('custom', $customPath, $this->docroot, 'en_US');

		$this->assertSame($customPath, $result);
		$this->assertDirectoryExists($customPath);
	}

	public function testRejectsCustomPathThatIsNotAbsolute(): void
	{
		$this->settingsSaver->expects($this->never())->method('saveSettings');

		$this->expectException(\InvalidArgumentException::class);

		$this->installer->install('custom', 'relative/tcms-data', $this->docroot, 'en_US');
	}

	public function testMigratesAutoBootstrapDirectoryToChosenPath(): void
	{
		// Simulate an in-flight wizard: bootstrap auto-created the docroot
		// candidate and dropped its extension state into it.
		$autoCreated = $this->docroot . '/tcms-data';
		mkdir($autoCreated . '/.system', 0755, true);
		file_put_contents($autoCreated . '/.system/extensions.json', '{"discovered":true}');

		$result = $this->installer->install('default', '', $this->docroot, 'en_US');

		$expected = $this->sandbox . '/tcms-data';
		$this->assertSame($expected, $result);
		$this->assertDirectoryDoesNotExist($autoCreated, 'Auto-created docroot dir should have moved.');
		$this->assertSame(
			'{"discovered":true}',
			(string)file_get_contents($expected . '/.system/extensions.json'),
			'Bootstrap state must survive the move into the chosen path.',
		);
	}

	public function testWritesLocaleToSettingsJson(): void
	{
		$this->installer->install('default', '', $this->docroot, 'fr_FR');

		$settings = json_decode((string)file_get_contents($this->sandbox . '/tcms-data/.system/settings.json'), true);
		// 3.5+: wizard writes to `i18n.default` (canonical) rather than the
		// top-level `locale` key (now reserved as an advanced-override path).
		$this->assertArrayHasKey('i18n', $settings);
		$this->assertSame('fr_FR', $settings['i18n']['default']);
		$this->assertArrayNotHasKey('locale', $settings);
	}
}

/**
 * Helper: recursively remove a directory tree (including non-empty dirs).
 */
function removeRecursive(string $path): void
{
	if (!is_dir($path)) {
		if (is_file($path) || is_link($path)) {
			@unlink($path);
		}

		return;
	}

	$entries = scandir($path);
	if ($entries === false) {
		return;
	}

	foreach ($entries as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$child = $path . '/' . $entry;
		if (is_dir($child) && !is_link($child)) {
			removeRecursive($child);
		} else {
			@unlink($child);
		}
	}

	@rmdir($path);
}
