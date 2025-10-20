<?php

namespace Tests\Unit\Domain\Settings\Services;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;

final class SettingsFetcherTest extends TestCase
{
	private SettingsFetcher $fetcher;
	private string $originalDocRoot;
	private string $testDocRoot;

	protected function setUp(): void
	{
		$this->fetcher = new SettingsFetcher();

		// Save original DOCUMENT_ROOT and set up test directory
		$this->originalDocRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$this->testDocRoot     = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		mkdir($this->testDocRoot, 0777, true);
		$_SERVER['DOCUMENT_ROOT'] = $this->testDocRoot;
	}

	protected function tearDown(): void
	{
		// Restore original DOCUMENT_ROOT
		$_SERVER['DOCUMENT_ROOT'] = $this->originalDocRoot;

		// Clean up test directory
		if (file_exists($this->testDocRoot . '/tcms.php')) {
			unlink($this->testDocRoot . '/tcms.php');
		}
		if (is_dir($this->testDocRoot)) {
			rmdir($this->testDocRoot);
		}
	}

	public function testLoadSettingsReturnsEmptyArrayWhenFileNotExists(): void
	{
		$result = $this->fetcher->loadSettings();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSettingsReturnsArrayFromFile(): void
	{
		$settings = [
			'sentry'  => 'test-sentry',
			'datadir' => 'test-data',
			'cache'   => ['enabled' => true],
		];

		$this->createTcmsPhpFile($settings);

		$result = $this->fetcher->loadSettings();

		$this->assertSame($settings, $result);
	}

	public function testLoadSettingsHandlesNonArrayReturn(): void
	{
		file_put_contents($this->testDocRoot . '/tcms.php', '<?php return "not an array";');

		$result = $this->fetcher->loadSettings();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSectionReturnsEmptyArrayWhenSettingsEmpty(): void
	{
		$result = $this->fetcher->loadSection('cache');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSectionReturnsSpecificSection(): void
	{
		$settings = [
			'sentry' => 'test',
			'cache'  => [
				'enabled' => true,
				'ttl'     => 3600,
			],
			'mailer' => [
				'host' => 'smtp.example.com',
			],
		];

		$this->createTcmsPhpFile($settings);

		$result = $this->fetcher->loadSection('cache');

		$this->assertSame($settings['cache'], $result);
	}

	public function testLoadSectionReturnsEmptyArrayForNonexistentSection(): void
	{
		$settings = [
			'cache' => ['enabled' => true],
		];

		$this->createTcmsPhpFile($settings);

		$result = $this->fetcher->loadSection('nonexistent');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSectionHandlesGeneralSettingsSpecially(): void
	{
		$settings = [
			'sentry'   => 'test-sentry',
			'datadir'  => 'test-data',
			'api'      => 'test-api',
			'notfound' => '404.html',
			'timezone' => 'UTC',
			'locale'   => 'en_US',
			'cache'    => ['enabled' => true], // This should not be in general
		];

		$this->createTcmsPhpFile($settings);

		$result = $this->fetcher->loadSection('general');

		$this->assertArrayHasKey('sentry', $result);
		$this->assertArrayHasKey('datadir', $result);
		$this->assertArrayHasKey('api', $result);
		$this->assertArrayHasKey('notfound', $result);
		$this->assertArrayHasKey('timezone', $result);
		$this->assertArrayHasKey('locale', $result);
		$this->assertArrayNotHasKey('cache', $result);
	}

	public function testLoadSectionGeneralReturnsOnlyPresentFields(): void
	{
		$settings = [
			'sentry'   => 'test-sentry',
			'timezone' => 'UTC',
			// Missing other general fields
		];

		$this->createTcmsPhpFile($settings);

		$result = $this->fetcher->loadSection('general');

		$this->assertCount(2, $result);
		$this->assertArrayHasKey('sentry', $result);
		$this->assertArrayHasKey('timezone', $result);
	}

	public function testLoadSettingsHandlesComplexNestedArrays(): void
	{
		$settings = [
			'cache' => [
				'redis' => [
					'host'    => 'localhost',
					'port'    => 6379,
					'options' => ['timeout' => 30],
				],
			],
		];

		$this->createTcmsPhpFile($settings);

		$result = $this->fetcher->loadSettings();

		$this->assertSame($settings, $result);
	}

	public function testLoadSectionMultipleSections(): void
	{
		$settings = [
			'cache'      => ['enabled' => true],
			'mailer'     => ['host' => 'smtp.example.com'],
			'imageworks' => ['quality' => 85],
		];

		$this->createTcmsPhpFile($settings);

		$cacheResult      = $this->fetcher->loadSection('cache');
		$mailerResult     = $this->fetcher->loadSection('mailer');
		$imageworksResult = $this->fetcher->loadSection('imageworks');

		$this->assertSame($settings['cache'], $cacheResult);
		$this->assertSame($settings['mailer'], $mailerResult);
		$this->assertSame($settings['imageworks'], $imageworksResult);
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function createTcmsPhpFile(array $settings): void
	{
		$content = '<?php return ' . var_export($settings, true) . ';';
		file_put_contents($this->testDocRoot . '/tcms.php', $content);
	}
}
