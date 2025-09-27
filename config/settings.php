<?php

// Defaults
$settings = require __DIR__ . '/defaults.php';

// This is used to keep stacks integration working
// Make sure this always comes before the env settings though or preview may break
if (file_exists(__DIR__ . '/tcms.php')) {
	require __DIR__ . '/tcms.php';
}

// Unit-test and integration environment (Travis CI)
$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if ($environment) {
	$envSettings = __DIR__ . '/local.' . strtolower((string)$environment) . '.php';
	if (file_exists($envSettings)) {
		require $envSettings;
	}
}

// User defined settings
if (file_exists(($_SERVER['DOCUMENT_ROOT'] ?? '') . '/tcms.php')) {
	$userSettings = require ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/tcms.php';

	if (is_array($userSettings)) {
		$userSettingsMap = [
			'sentry'              => 'sentry/enable',
			'presets'             => 'imageworks/presets',
			'watermarksGallery'   => 'imageworks/watermarksGallery',
			'watermarkFontsDepot' => 'imageworks/watermarkFontsDepot',
		];
		foreach ($userSettings as $key => $value) {
			if (isset($userSettingsMap[$key])) {
				$keys = explode('/', $userSettingsMap[$key]);
				$temp = &$settings;
				// loop through the userSetting map and set the values in the main settings
				foreach ($keys as $key) {
					if (!isset($temp[$key])) {
						$temp[$key] = [];
					}
					$temp = &$temp[$key];
				}
				$temp = $value;
				continue;
			}
			$settings[$key] = $value;
		}
	}
}

// Validate test environment - prevent production bypass attempts
if (($settings['env'] ?? '') === 'test') {
	// Check multiple indicators that this is a legitimate test environment
	$testIndicators = [
		// PHPUnit/Pest testing frameworks are active
		defined('PHPUNIT_RUNNING') || class_exists('PHPUnit\\Framework\\TestCase'),
		function_exists('test') || function_exists('describe'),
		// CLI environment (tests typically run from command line)
		php_sapi_name() === 'cli',
		// Test-specific domain
		($settings['domain'] ?? '') === 'totalcms.test',
		// Test data directory is being used
		str_contains($settings['datadir'] ?? '', '/tests/'),
	];

	// If less than 2 indicators confirm this is a test environment, force to production
	$confirmedIndicators = array_filter($testIndicators);
	if (count($confirmedIndicators) < 2) {
		$settings['env'] = 'prod';
		// Log the security event
		error_log('Security: Attempted test environment bypass detected, forced to production mode');
	}
}

// echo "<pre>";
// print_r($settings);
// echo "</pre>";
return $settings;
