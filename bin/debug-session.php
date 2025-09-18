<?php
/**
 * Session Debug Script
 *
 * This script helps diagnose session configuration issues.
 * Place this in the root of each Total CMS installation and access via browser.
 */

// Start session to see current settings
session_start();

echo "<h1>Session Configuration Debug</h1>";
echo "<h2>Domain: " . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "</h2>";

echo "<h3>Session Settings</h3>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";

$sessionSettings = [
    'session.name' => session_name(),
    'session.save_path' => session_save_path(),
    'session.cookie_domain' => ini_get('session.cookie_domain'),
    'session.cookie_path' => ini_get('session.cookie_path'),
    'session.cookie_secure' => ini_get('session.cookie_secure') ? 'true' : 'false',
    'session.cookie_httponly' => ini_get('session.cookie_httponly') ? 'true' : 'false',
    'session.cookie_samesite' => ini_get('session.cookie_samesite'),
    'session.use_only_cookies' => ini_get('session.use_only_cookies') ? 'true' : 'false',
    'session.id' => session_id(),
];

foreach ($sessionSettings as $setting => $value) {
    echo "<tr><td>$setting</td><td>" . htmlspecialchars((string)$value) . "</td></tr>";
}
echo "</table>";

echo "<h3>Environment Variables</h3>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Variable</th><th>Value</th></tr>";

$envVars = [
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'not set',
    'HTTPS' => $_SERVER['HTTPS'] ?? 'not set',
    'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'not set',
    'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? 'not set',
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'not set',
];

foreach ($envVars as $var => $value) {
    echo "<tr><td>$var</td><td>" . htmlspecialchars((string)$value) . "</td></tr>";
}
echo "</table>";

echo "<h3>Calculated Values</h3>";
$domain = $_SERVER['HTTP_HOST'] ?? 'unknown';
$domainHash = md5($domain);
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
           isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Calculated Value</th><th>Result</th></tr>";
echo "<tr><td>Domain Hash (MD5)</td><td>$domainHash</td></tr>";
echo "<tr><td>Expected Session Name</td><td>tcms_$domainHash</td></tr>";
echo "<tr><td>Is HTTPS</td><td>" . ($isHttps ? 'true' : 'false') . "</td></tr>";
echo "<tr><td>Expected Cookie Secure</td><td>" . ($isHttps ? 'true' : 'false') . "</td></tr>";
echo "</table>";

echo "<h3>Test Session Data</h3>";
$_SESSION['debug_test'] = 'Domain: ' . $domain . ' - Time: ' . date('Y-m-d H:i:s');
echo "Set test session data: " . htmlspecialchars($_SESSION['debug_test']);

echo "<h3>Session File Path</h3>";
$expectedPath = dirname(__DIR__) . '/tmp/sessions/' . $domainHash;
echo "Expected session directory: " . htmlspecialchars($expectedPath) . "<br>";
echo "Directory exists: " . (is_dir($expectedPath) ? 'YES' : 'NO') . "<br>";
if (is_dir($expectedPath)) {
    $files = glob($expectedPath . '/sess_*');
    echo "Session files in directory: " . count($files) . "<br>";
}

echo "<h3>Cookie Information</h3>";
echo "Current cookies:<br>";
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'tcms_') === 0 || $name === 'PHPSESSID') {
        echo "- $name = " . htmlspecialchars($value) . "<br>";
    }
}