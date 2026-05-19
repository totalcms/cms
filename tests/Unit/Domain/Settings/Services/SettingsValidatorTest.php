<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Settings\Services;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Settings\Services\SettingsValidator;

final class SettingsValidatorTest extends TestCase
{
	private SettingsValidator $validator;

	protected function setUp(): void
	{
		$this->validator = new SettingsValidator();
	}

	// ==================== Section Validation ====================

	public function testIsValidSectionReturnsTrueForValidSections(): void
	{
		$validSections = ['installation', 'general', 'dashboard', 'imageworks', 'smtp', 'cache', 'auth', 'htmlclean', 'mailer'];

		foreach ($validSections as $section) {
			$this->assertTrue(
				$this->validator->isValidSection($section),
				"Section '{$section}' should be valid"
			);
		}
	}

	public function testIsValidSectionReturnsFalseForInvalidSections(): void
	{
		$invalidSections = ['invalid', 'unknown', 'random', 'session'];

		foreach ($invalidSections as $section) {
			$this->assertFalse(
				$this->validator->isValidSection($section),
				"Section '{$section}' should be invalid"
			);
		}
	}

	public function testGetValidSectionsReturnsAllValidSections(): void
	{
		$sections = $this->validator->getValidSections();

		$this->assertIsArray($sections);
		$this->assertCount(15, $sections);
		$this->assertContains('installation', $sections);
		$this->assertContains('general', $sections);
		$this->assertContains('auth', $sections);
		$this->assertContains('builder', $sections);
		$this->assertContains('cache', $sections);
		$this->assertContains('dashboard', $sections);
		$this->assertContains('htmlclean', $sections);
		$this->assertContains('i18n', $sections);
		$this->assertContains('imageworks', $sections);
		$this->assertContains('license', $sections);
		$this->assertContains('mailer', $sections);
		$this->assertContains('presets', $sections);
		$this->assertContains('pushnotif', $sections);
		$this->assertContains('smtp', $sections);
		$this->assertContains('sync', $sections);
	}

	// ==================== Dashboard Section ====================

	public function testProcessDashboardNormalizesColorObjectToHexString(): void
	{
		$data = [
			'pagination' => '50',
			'accent'     => [
				'hex'   => '#e24bd5',
				'oklch' => 'some-oklch-value',
			],
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertIsString($result['accent']);
		$this->assertEquals('#e24bd5', $result['accent']);
		$this->assertArrayNotHasKey('oklch', $result);
	}

	public function testProcessDashboardAddsHashPrefixToHexString(): void
	{
		$data = [
			'accent' => 'e24bd5', // Missing # prefix
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertEquals('#e24bd5', $result['accent']);
	}

	public function testProcessDashboardPreservesHashPrefixOnHexString(): void
	{
		$data = [
			'accent' => '#4d91e2', // Already has # prefix
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertEquals('#4d91e2', $result['accent']);
	}

	public function testProcessDashboardConvertsPaginationToInteger(): void
	{
		$data = [
			'pagination' => '100',
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertIsInt($result['pagination']);
		$this->assertEquals(100, $result['pagination']);
	}

	public function testProcessDashboardHandlesNumericPagination(): void
	{
		$data = [
			'pagination' => 50,
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertIsInt($result['pagination']);
		$this->assertEquals(50, $result['pagination']);
	}

	public function testProcessDashboardHandlesEmptyValues(): void
	{
		$data = [
			'pagination' => '50',
			'title'      => '',
			'accent'     => '#4d91e2',
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertArrayHasKey('title', $result, 'Empty strings should be preserved to allow clearing values');
		$this->assertEquals('', $result['title']);
		$this->assertEquals(50, $result['pagination']);
		$this->assertEquals('#4d91e2', $result['accent']);
	}

	// ==================== ImageWorks Section ====================

	public function testProcessImageWorksDecodesPresetsJSON(): void
	{
		$presets = [
			'small'  => ['w' => 300, 'h' => 200],
			'medium' => ['w' => 600, 'h' => 400],
		];

		$data = [
			'presets' => json_encode($presets),
		];

		$result = $this->validator->processSection('imageworks', $data);

		$this->assertIsArray($result['presets']);
		$this->assertEquals($presets, $result['presets']);
	}

	public function testProcessImageWorksDecodesDefaultsJSON(): void
	{
		$defaults = [
			'fm' => 'jpg',
			'q'  => 92,
		];

		$data = [
			'defaults' => json_encode($defaults),
		];

		$result = $this->validator->processSection('imageworks', $data);

		$this->assertIsArray($result['defaults']);
		$this->assertEquals($defaults, $result['defaults']);
	}

	public function testProcessImageWorksHandlesInvalidJSON(): void
	{
		$data = [
			'presets'  => 'invalid-json{',
			'defaults' => 'also-invalid',
		];

		$result = $this->validator->processSection('imageworks', $data);

		// Invalid JSON should remain as string
		$this->assertIsString($result['presets']);
		$this->assertIsString($result['defaults']);
	}

	public function testProcessImageWorksPreservesArrayValues(): void
	{
		$presets = ['small' => ['w' => 300]];

		$data = [
			'presets' => $presets, // Already an array
		];

		$result = $this->validator->processSection('imageworks', $data);

		$this->assertIsArray($result['presets']);
		$this->assertEquals($presets, $result['presets']);
	}

	// ==================== Cache Section ====================

	public function testProcessCacheConvertsBackendTogglesToBooleans(): void
	{
		$testCases = [
			['apcu' => 'on', 'expected' => true],
			['redis'      => '1', 'expected' => true],
			['memcached'  => true, 'expected' => true],
			['filesystem' => 'off', 'expected' => false],
			['apcu'       => '0', 'expected' => false],
			['redis'      => false, 'expected' => false],
		];

		foreach ($testCases as $testCase) {
			$backend = array_key_first($testCase);
			$result  = $this->validator->processSection('cache', [$backend => $testCase[$backend]]);
			$this->assertEquals(
				$testCase['expected'],
				$result[$backend],
				"Cache backend '{$backend}' value '{$testCase[$backend]}' should convert to " . ($testCase['expected'] ? 'true' : 'false')
			);
		}
	}

	public function testProcessCacheDecodesConfigurationObjects(): void
	{
		$redisConfig     = ['host' => '127.0.0.1', 'port' => 6379];
		$memcachedConfig = ['host' => 'localhost', 'port' => 11211];

		$data = [
			'redis'           => 'on',
			'memcached'       => 'on',
			'redisConfig'     => json_encode($redisConfig),
			'memcachedConfig' => json_encode($memcachedConfig),
		];

		$result = $this->validator->processSection('cache', $data);

		$this->assertTrue($result['redis']);
		$this->assertTrue($result['memcached']);
		$this->assertIsArray($result['redisConfig']);
		$this->assertEquals($redisConfig, $result['redisConfig']);
		$this->assertIsArray($result['memcachedConfig']);
		$this->assertEquals($memcachedConfig, $result['memcachedConfig']);
	}

	public function testProcessCacheHandlesAllBackends(): void
	{
		$data = [
			'apcu'       => 'on',
			'redis'      => '1',
			'memcached'  => true,
			'filesystem' => 'on',
		];

		$result = $this->validator->processSection('cache', $data);

		$this->assertTrue($result['apcu']);
		$this->assertTrue($result['redis']);
		$this->assertTrue($result['memcached']);
		$this->assertTrue($result['filesystem']);
	}

	public function testProcessCacheHandlesInvalidJSON(): void
	{
		$data = [
			'redis'       => 'on',
			'redisConfig' => 'invalid-json{',
		];

		$result = $this->validator->processSection('cache', $data);

		$this->assertTrue($result['redis']);
		// Invalid JSON should remain as string
		$this->assertIsString($result['redisConfig']);
	}

	// ==================== Auth Section ====================

	public function testProcessAuthConvertsEnableToggle(): void
	{
		$testCases = [
			['enable' => 'on', 'expected' => true],
			['enable' => '1', 'expected' => true],
			['enable' => true, 'expected' => true],
			['enable' => 'off', 'expected' => false],
			['enable' => '0', 'expected' => false],
			['enable' => false, 'expected' => false],
		];

		foreach ($testCases as $testCase) {
			$result = $this->validator->processSection('auth', ['enable' => $testCase['enable']]);
			$this->assertEquals(
				$testCase['expected'],
				$result['enable'],
				"Enable value '{$testCase['enable']}' should convert to " . ($testCase['expected'] ? 'true' : 'false')
			);
		}
	}

	public function testProcessAuthConvertsNumericFields(): void
	{
		$data = [
			'maxAttempts'         => '10',
			'deniedTimeout'       => '7',
			'persistentLoginDays' => '30',
		];

		$result = $this->validator->processSection('auth', $data);

		$this->assertIsInt($result['maxAttempts']);
		$this->assertEquals(10, $result['maxAttempts']);
		$this->assertIsInt($result['deniedTimeout']);
		$this->assertEquals(7, $result['deniedTimeout']);
		$this->assertIsInt($result['persistentLoginDays']);
		$this->assertEquals(30, $result['persistentLoginDays']);
	}

	public function testProcessAuthHandlesAlreadyIntegerValues(): void
	{
		$data = [
			'maxAttempts'         => 5,
			'deniedTimeout'       => 3,
			'persistentLoginDays' => 15,
		];

		$result = $this->validator->processSection('auth', $data);

		$this->assertEquals(5, $result['maxAttempts']);
		$this->assertEquals(3, $result['deniedTimeout']);
		$this->assertEquals(15, $result['persistentLoginDays']);
	}

	// ==================== HtmlClean Section ====================

	public function testProcessHtmlCleanConvertsEnabledToggle(): void
	{
		$testCases = [
			['enabled' => 'on', 'expected' => true],
			['enabled' => '1', 'expected' => true],
			['enabled' => true, 'expected' => true],
			['enabled' => 'off', 'expected' => false],
			['enabled' => '0', 'expected' => false],
			['enabled' => false, 'expected' => false],
		];

		foreach ($testCases as $testCase) {
			$result = $this->validator->processSection('htmlclean', ['enabled' => $testCase['enabled']]);
			$this->assertEquals(
				$testCase['expected'],
				$result['enabled'],
				"Enabled value '{$testCase['enabled']}' should convert to " . ($testCase['expected'] ? 'true' : 'false')
			);
		}
	}

	public function testProcessHtmlCleanDecodesAllowedCssProperties(): void
	{
		$properties = ['color', 'background-color', 'font-size', 'margin'];

		$data = [
			'allowed_css_properties' => json_encode($properties),
		];

		$result = $this->validator->processSection('htmlclean', $data);

		$this->assertIsArray($result['allowed_css_properties']);
		$this->assertEquals($properties, $result['allowed_css_properties']);
	}

	public function testProcessHtmlCleanDecodesAllowedTags(): void
	{
		$tags = ['p', 'strong', 'em', 'a', 'ul', 'li'];

		$data = [
			'allowed_tags' => json_encode($tags),
		];

		$result = $this->validator->processSection('htmlclean', $data);

		$this->assertIsArray($result['allowed_tags']);
		$this->assertEquals($tags, $result['allowed_tags']);
	}

	public function testProcessHtmlCleanDecodesAllowedIframeDomains(): void
	{
		$domains = ['www.youtube.com', 'vimeo.com'];

		$data = [
			'allowed_iframe_domains' => json_encode($domains),
		];

		$result = $this->validator->processSection('htmlclean', $data);

		$this->assertIsArray($result['allowed_iframe_domains']);
		$this->assertEquals($domains, $result['allowed_iframe_domains']);
	}

	public function testProcessHtmlCleanHandlesAllJSONFields(): void
	{
		$properties = ['color', 'margin'];
		$tags       = ['p', 'strong'];
		$domains    = ['youtube.com'];

		$data = [
			'enabled'                => 'on',
			'allowed_css_properties' => json_encode($properties),
			'allowed_tags'           => json_encode($tags),
			'allowed_iframe_domains' => json_encode($domains),
		];

		$result = $this->validator->processSection('htmlclean', $data);

		$this->assertTrue($result['enabled']);
		$this->assertEquals($properties, $result['allowed_css_properties']);
		$this->assertEquals($tags, $result['allowed_tags']);
		$this->assertEquals($domains, $result['allowed_iframe_domains']);
	}

	public function testProcessHtmlCleanHandlesInvalidJSON(): void
	{
		$data = [
			'allowed_css_properties' => 'invalid-json{',
			'allowed_tags'           => 'also-invalid',
		];

		$result = $this->validator->processSection('htmlclean', $data);

		// Invalid JSON should remain as string
		$this->assertIsString($result['allowed_css_properties']);
		$this->assertIsString($result['allowed_tags']);
	}

	// ==================== General Section ====================

	public function testProcessGeneralReturnsDataUnchanged(): void
	{
		$data = [
			'timezone' => 'America/New_York',
			'datadir'  => '/custom/path',
			'debug'    => true,
		];

		$result = $this->validator->processSection('general', $data);

		$this->assertEquals($data, $result);
	}

	public function testProcessGeneralRemovesEmptyValues(): void
	{
		$data = [
			'timezone' => 'UTC',
			'datadir'  => '',
			'debug'    => true,
		];

		$result = $this->validator->processSection('general', $data);

		$this->assertArrayHasKey('datadir', $result, 'Empty strings should be preserved to allow clearing values');
		$this->assertEquals('', $result['datadir']);
		$this->assertEquals('UTC', $result['timezone']);
		$this->assertTrue($result['debug']);
	}

	// ==================== Presets Section ====================

	public function testProcessPresetsDecodesOuterJSON(): void
	{
		$presets = [
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => '{"height":400,"toolbar":"basic"}',
			],
		];

		$data = [
			'presetsettings' => json_encode($presets),
		];

		$result = $this->validator->processSection('presets', $data);

		$this->assertIsArray($result['presetsettings']);
		$this->assertArrayHasKey('blog-editor', $result['presetsettings']);
		$this->assertIsArray($result['presetsettings']['blog-editor']['settings']);
		$this->assertEquals(400, $result['presetsettings']['blog-editor']['settings']['height']);
		$this->assertEquals('basic', $result['presetsettings']['blog-editor']['settings']['toolbar']);
	}

	public function testProcessPresetsDecodesMultipleItems(): void
	{
		$presets = [
			'blog-editor' => [
				'id'       => 'blog-editor',
				'settings' => '{"height":400}',
			],
			'styledtext' => [
				'id'       => 'styledtext',
				'settings' => '{"toolbar":"minimal","height":300}',
			],
		];

		$data = [
			'presetsettings' => json_encode($presets),
		];

		$result = $this->validator->processSection('presets', $data);

		$this->assertIsArray($result['presetsettings']);
		$this->assertCount(2, $result['presetsettings']);
		$this->assertIsArray($result['presetsettings']['blog-editor']['settings']);
		$this->assertIsArray($result['presetsettings']['styledtext']['settings']);
		$this->assertEquals(300, $result['presetsettings']['styledtext']['settings']['height']);
	}

	public function testProcessPresetsHandlesEmptyPresetsettings(): void
	{
		$data = [
			'presetsettings' => '',
		];

		$result = $this->validator->processSection('presets', $data);

		$this->assertEquals('', $result['presetsettings']);
	}

	public function testProcessPresetsHandlesInvalidOuterJSON(): void
	{
		$data = [
			'presetsettings' => 'invalid-json{',
		];

		$result = $this->validator->processSection('presets', $data);

		// Invalid JSON should remain as string
		$this->assertIsString($result['presetsettings']);
	}

	public function testProcessPresetsHandlesInvalidItemSettingsJSON(): void
	{
		$presets = [
			'bad-preset' => [
				'id'       => 'bad-preset',
				'settings' => 'not-valid-json{',
			],
		];

		$data = [
			'presetsettings' => json_encode($presets),
		];

		$result = $this->validator->processSection('presets', $data);

		// Item with invalid settings JSON should keep the string
		$this->assertIsString($result['presetsettings']['bad-preset']['settings']);
	}

	public function testProcessPresetsPreservesArraySettingsAlreadyDecoded(): void
	{
		// If presetsettings is already an array (not a JSON string), pass through
		$data = [
			'presetsettings' => [
				'blog-editor' => [
					'id'       => 'blog-editor',
					'settings' => ['height' => 400],
				],
			],
		];

		$result = $this->validator->processSection('presets', $data);

		// Already an array, should pass through unchanged
		$this->assertIsArray($result['presetsettings']);
		$this->assertIsArray($result['presetsettings']['blog-editor']['settings']);
	}

	public function testProcessPresetsHandlesMissingPresetsettingsKey(): void
	{
		$data = [
			'someOtherField' => 'value',
		];

		$result = $this->validator->processSection('presets', $data);

		$this->assertArrayNotHasKey('presetsettings', $result);
		$this->assertEquals('value', $result['someOtherField']);
	}

	// ==================== Unknown Section ====================

	public function testProcessUnknownSectionReturnsDataUnchanged(): void
	{
		$data = [
			'field1' => 'value1',
			'field2' => 'value2',
		];

		$result = $this->validator->processSection('unknown', $data);

		$this->assertEquals($data, $result);
	}

	public function testProcessUnknownSectionStillRemovesEmptyValues(): void
	{
		$data = [
			'field1' => 'value1',
			'field2' => '',
			'field3' => 'value3',
		];

		$result = $this->validator->processSection('unknown', $data);

		$this->assertArrayHasKey('field2', $result, 'Empty strings should be preserved to allow clearing values');
		$this->assertEquals('', $result['field2']);
		$this->assertEquals('value1', $result['field1']);
		$this->assertEquals('value3', $result['field3']);
	}

	// ==================== Edge Cases ====================

	public function testProcessSectionHandlesEmptyArray(): void
	{
		$result = $this->validator->processSection('dashboard', []);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testProcessSectionPreservesNonEmptyStringValues(): void
	{
		$data = [
			'title'      => 'My Dashboard',
			'pagination' => '50',
		];

		$result = $this->validator->processSection('dashboard', $data);

		$this->assertEquals('My Dashboard', $result['title']);
		$this->assertEquals(50, $result['pagination']);
	}

	public function testProcessSectionPreservesZeroValues(): void
	{
		$data = [
			'maxAttempts'   => 0,
			'deniedTimeout' => 0,
		];

		$result = $this->validator->processSection('auth', $data);

		$this->assertEquals(0, $result['maxAttempts']);
		$this->assertEquals(0, $result['deniedTimeout']);
	}

	public function testProcessSectionPreservesFalseValues(): void
	{
		$resultAuth = $this->validator->processSection('auth', ['enable' => false]);
		$resultHtml = $this->validator->processSection('htmlclean', ['enabled' => false]);

		$this->assertFalse($resultAuth['enable']);
		$this->assertFalse($resultHtml['enabled']);
	}
}
