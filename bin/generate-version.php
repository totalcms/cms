#!/usr/bin/env php
<?php

/**
 * Generate version.json with HMAC signature.
 *
 * Usage: php bin/generate-version.php <version> <build> [output-file] [commits]
 *
 * Example: php bin/generate-version.php 3.1.3 5e3c5139
 *          php bin/generate-version.php 3.1.3 5e3c5139 version.json 4
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TotalCMS\Support\Version;

$argc = $argc ?? 0;
$argv = $argv ?? [];

if ($argc < 3) {
	echo "Usage: php bin/generate-version.php <version> <build> [output-file]\n";
	echo "Example: php bin/generate-version.php 3.1.3 5e3c5139\n";
	exit(1);
}

$version    = $argv[1];
$build      = $argv[2];
$outputFile = $argv[3] ?? __DIR__ . '/../version.json';
$commits    = (int)($argv[4] ?? 0);
$date       = date('Y-m-d');

// Validate version format (SemVer: X.Y.Z with optional prerelease suffix like -beta.1, -rc1)
if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
	echo "Error: Invalid version format. Expected X.Y.Z or X.Y.Z-prerelease (e.g., 3.1.3, 3.3.0-beta.1)\n";
	exit(1);
}

// Validate build format (git short hash)
if (!preg_match('/^[a-f0-9]+$/', $build)) {
	echo "Error: Invalid build format. Expected git short hash (e.g., 5e3c5139)\n";
	exit(1);
}

// Generate signature
$signature = Version::generateSignature($version, $date);

$data = [
	'version'   => $version,
	'build'     => $build,
];

if ($commits > 0) {
	$data['commits'] = $commits;
}

$data['date']      = $date;
$data['signature'] = $signature;

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (file_put_contents($outputFile, $json) === false) {
	echo "Error: Failed to write to $outputFile\n";
	exit(1);
}

echo "Generated version.json: $version-$build ($date)\n";
