#!/usr/bin/env php
<?php

/**
 * Test script for emergency cache clearing functionality
 * Usage: php bin/test-emergency-cache.php [base_url].
 */
if (php_sapi_name() !== 'cli') {
	exit('This script can only be run from the command line.');
}

$baseUrl = $argv[1] ?? 'http://localhost';
$baseUrl = rtrim($baseUrl, '/');

echo "=== TotalCMS Emergency Cache Clear Test ===\n";
echo "Base URL: $baseUrl\n";
echo 'Date: ' . date('Y-m-d H:i:s') . "\n\n";

// Test the emergency endpoint (no authentication required)
$testUrl = "$baseUrl/emergency/cache/clear";
echo "Testing: $testUrl\n\n";

$context = stream_context_create([
	'http' => [
		'method'  => 'GET',
		'timeout' => 30,
		'header'  => [
			'Accept: application/json',
			'User-Agent: TotalCMS-Emergency-Test/1.0',
		],
	],
]);

$response           = @file_get_contents($testUrl, false, $context);
$httpResponseHeader = $http_response_header ?? [];

if ($response === false) {
	echo "❌ Failed to connect to emergency endpoint\n";
	echo "This is expected if:\n";
	echo "  - TotalCMS is not running on $baseUrl\n";
	echo "  - The endpoint is not accessible from this location\n";
	echo "  - Network connectivity issues\n\n";
	exit(1);
}

// Parse response
$data       = json_decode($response, true);
$statusLine = $httpResponseHeader[0] ?? 'Unknown';

echo "Response Status: $statusLine\n";
echo "Response Body:\n";
echo json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

// Check if successful
if (isset($data['success']) && $data['success'] === true) {
	echo "✅ Emergency cache clear test PASSED\n";
	echo "   - Endpoint is accessible\n";
	echo "   - No authentication required\n";
	echo "   - Cache clearing executed\n";
	echo '   - Cleared caches: ' . implode(', ', array_keys($data['cleared'] ?? [])) . "\n";
} elseif (isset($data['error'])) {
	echo "⚠️  Emergency cache clear test returned an error:\n";
	echo '   Error: ' . $data['error'] . "\n";
	if (isset($data['details'])) {
		echo '   Details: ' . $data['details'] . "\n";
	}
} else {
	echo "❌ Unexpected response format\n";
}

echo "\n=== Instructions for Emergency Use ===\n";
echo "When TotalCMS admin is inaccessible due to cached errors:\n";
echo "1. Visit: $baseUrl/emergency/cache/clear\n";
echo "2. Or run: curl '$baseUrl/emergency/cache/clear'\n";
echo "3. No authentication or special access required\n";
echo "4. Works from any location (customer-friendly)\n";
echo "5. Clears all caches including OPcache\n\n";
