<?php

use TotalCMS\Domain\Settings\Services\SettingsSaver;

// Defaults
$settings = require __DIR__ . '/defaults.php';

// Load configuration overrides
// For Composer installs: project-level config/tcms.php
// For zip installs: package-level config/tcms.php (Stacks integration)
$projectTcms = \TotalCMS\Support\PathResolver::projectRoot() . '/config/tcms.php';
$packageTcms = __DIR__ . '/tcms.php';

if (\TotalCMS\Support\PathResolver::isComposerInstall() && file_exists($projectTcms)) {
	$installationSettings = require $projectTcms;
	if (is_array($installationSettings)) {
		$settings = array_replace_recursive($settings, $installationSettings);
	}
} elseif (file_exists($packageTcms)) {
	require $packageTcms;
}

// Unit-test and integration environment (Travis CI)
$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if ($environment) {
	$envSettings = __DIR__ . '/local.' . strtolower((string)$environment) . '.php';
	if (file_exists($envSettings)) {
		require $envSettings;
	}
}

// Load installation settings from tcms.php (bootstrap configuration like datadir)
if (file_exists(($_SERVER['DOCUMENT_ROOT'] ?? '') . '/tcms.php')) {
	$installationSettings = require ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/tcms.php';

	if (is_array($installationSettings)) {
		// Deep merge installation settings (only datadir for now)
		$settings = array_replace_recursive($settings, $installationSettings);
	}
}

// Load user settings from settings.json in tcms-data/.system/
$settingsJsonFile = $settings['datadir'] . '/.system/settings.json';
if (file_exists($settingsJsonFile)) {
	$settingsJsonContent = file_get_contents($settingsJsonFile);
	if ($settingsJsonContent !== false) {
		$userSettings = json_decode($settingsJsonContent, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($userSettings)) {
			// Use the deep merge method from SettingsSaver
			$settings = SettingsSaver::deepMergeArrays($settings, $userSettings);
		}
	}
}

// Validate test environment - prevent production bypass attempts
if (($settings['env'] ?? '') === 'test') {
	// Check multiple indicators that this is a legitimate test environment
	$testIndicators = [
		// PHPUnit/Pest testing frameworks are active
		defined('PHPUNIT_RUNNING') || class_exists(PHPUnit\Framework\TestCase::class),
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
