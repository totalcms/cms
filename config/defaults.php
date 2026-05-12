<?php

// Configure defaults for the whole application.

// Error reporting
error_reporting(0);
ini_set('display_errors', '0');

if (isset($_GET['debugstart'])) {
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
}

// locale
setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'en_US');

// JSON fix for saving float values
ini_set('serialize_precision', '-1');

// Cloudflare IP address header
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
}

// Settings
$settings = [];

// Sentry Error Tracking - set to false to disable
$settings['sentry'] = true;

// Default env to production
$settings['env']    = 'prod';
$settings['locale'] = 'en_US';

$settings['domain']   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
$settings['is_https'] = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
					   || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
					   || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$settings['url']             = ($settings['is_https'] ? 'https://' : 'http://') . $settings['domain'];
$settings['notfound']        = '/404';
$settings['maxDownloadSize'] = 2048;

// Path settings
$settings['root']     = TotalCMS\Support\PathResolver::projectRoot();
$settings['tmpdir']   = $settings['root'] . '/tmp';
$settings['cachedir'] = $settings['root'] . '/cache';
$settings['public']   = $settings['root'] . '/public';
$settings['template'] = TotalCMS\Support\PathResolver::packageRoot() . '/resources/templates';
$settings['schemas']  = TotalCMS\Support\PathResolver::packageRoot() . '/resources/schemas';

// Resolve DOCUMENT_ROOT: use server value if available, otherwise read from stored file.
// Web requests persist the docroot so CLI tools can discover it automatically.
$docrootFile         = $settings['cachedir'] . '/.docroot';
$settings['docroot'] = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', DIRECTORY_SEPARATOR);

if (!is_dir($settings['cachedir'])) {
	@mkdir($settings['cachedir'], 0755, true);
}

if ($settings['docroot'] !== '' && PHP_SAPI !== 'cli' && !file_exists($docrootFile)) {
	// Web request — persist for CLI tools (write once)
	@file_put_contents($docrootFile, $settings['docroot']);
} elseif ($settings['docroot'] === '' && file_exists($docrootFile)) {
	// CLI without DOCUMENT_ROOT — read stored value
	$storedDocroot = file_get_contents($docrootFile);
	if ($storedDocroot !== false && $storedDocroot !== '') {
		$settings['docroot']      = rtrim($storedDocroot, DIRECTORY_SEPARATOR);
		$_SERVER['DOCUMENT_ROOT'] = $settings['docroot'];
	}
}

// Last-resort fallback for CLI runs that happen before any web request
// has populated $docrootFile (the post-install hook is the canonical
// case — `composer create-project` runs `vendor/bin/tcms builder:init`
// before the operator has loaded the site once). Assume the standard
// Composer layout where the docroot is `<project>/public`. If that's
// wrong for a given install, the operator can override `datadir` (and
// other path-dependent settings) explicitly in `config/tcms.php`, OR
// the first web request will overwrite this on disk.
if ($settings['docroot'] === '') {
	$settings['docroot']      = $settings['root'] . '/public';
	$_SERVER['DOCUMENT_ROOT'] = $settings['docroot'];
}

// URL prefix where the front controller is mounted. For the typical
// install ("public/" is the doc root), this is empty. For subpath
// installs (e.g. https://example.com/cms/), this is the subpath
// ("/cms"). Derived from SCRIPT_NAME — must NOT be derived from
// filesystem paths because the project root is not necessarily a
// subdirectory of the document root.
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if ($scriptName === '' || !str_starts_with($scriptName, '/')) {
	// CLI, cli-server (after the bootstrap workaround rewrites
	// SCRIPT_NAME to a basename), or other unusual SAPIs.
	$settings['api'] = '';
} else {
	// dirname('/index.php')      === '/'    -> '' after rtrim
	// dirname('/cms/index.php')  === '/cms' -> '/cms'
	$settings['api'] = rtrim(dirname($scriptName), '/');
}

$settings['debug'] = false; // Set to true for development

// Cache configuration
// Priority: APCu > Redis > Memcached > Filesystem (optimized for single-server deployments)
$settings['cache'] = [
	'apcu'        => true,
	'filesystem'  => true,
	'redis'       => true,
	'memcached'   => true,
	'redisConfig' => [
		'host'     => '127.0.0.1',
		'port'     => 6379,
		'timeout'  => 1,
		'password' => null,
		'database' => 0,
	],
	'memcachedConfig' => [
		'host'    => '127.0.0.1',
		'port'    => 11211,
	],
];

// Path to tcms-data folder - smart detection
// Priority:
// Auto-detect tcms-data directory:
// 1. DOCUMENT_ROOT/../tcms-data if it exists
// 2. DOCUMENT_ROOT/tcms-data if it exists
// 3. Default to DOCUMENT_ROOT/tcms-data (setup wizard will create it)
// Note: DOCUMENT_ROOT/tcms.php can override this for custom paths
$parentDatadir = dirname($settings['docroot']) . '/tcms-data';
$localDatadir  = $settings['docroot'] . '/tcms-data';

// Auto-detect datadir: only use directories that actually exist
// Priority: parent (above docroot) > local (in docroot)
// Default to local (docroot) path
// The setup wizard will create the chosen directory
$settings['datadir'] = is_dir($parentDatadir) ? $parentDatadir : $localDatadir;

// Error Handling
$settings['error'] = [
	// Should be set to false in production
	'display_error_details' => false,
	// Parameter is passed to the default ErrorHandler
	// View in rendered output by enabling the "displayErrorDetails" setting.
	// For the console and unit tests it should be disabled too
	'log_errors' => true,
	// Display error details in error log
	'log_error_details' => true,
];

// Logger settings
$settings['logger'] = [
	'name'        => 'totalcms',
	'path'        => TotalCMS\Support\PathResolver::projectRoot() . '/logs',
	'filename'    => 'totalcms.log',
	'level'       => Monolog\Level::Info,
	'maxFiles'    => 10,
	'permissions' => 0775,
];

// Per-channel log level overrides controlled from /admin/settings (general).
// Names are PSR-3 strings: debug, info, notice, warning, error, critical, alert, emergency.
// When set, these override $settings['logger']['level'] for the matching log file.
$settings['appLogLevel']        = 'info';
$settings['extensionsLogLevel'] = 'info';

// Session
$settings['session'] = [
	// 'name'                   => 'tcms_' . md5($settings['domain']), // Domain-specific session name for isolation
	'name'                   => null, // Use PHP default session name
	'cookie_domain'          => '', // Empty domain for maximum subdomain isolation
	'cookie_path'            => '/', // Explicit path for cookie isolation
	'cookie_samesite'        => 'Lax',
	'cache_expire'           => 0,
	'cache_limiter'          => '', // Empty string prevents PHP from sending cache headers, allowing responses to control their own caching
	'cookie_secure'          => $settings['is_https'], // Only secure cookies over HTTPS
	'cookie_httponly'        => true,
	'cookie_lifetime'        => 0,
	// Session timeout for non-persistent logins (24 hours = 86400 seconds)
	// Note: Users with "Keep me logged in" enabled never timeout (handled by persistent login tokens)
	// This value affects server-side session garbage collection only
	// Can be overridden in tcms.php if needed for specific use cases
	'gc_maxlifetime'         => 86400, // 24 hours - generous for long form sessions
	'use_trans_sid'          => false,
	'use_only_cookies'       => true,
	// 'sid_length'             => 64,
	// 'sid_bits_per_character' => 6,
	// 'save_path'              => $settings['tmpdir'] . '/sessions/' . md5($settings['domain']), // Domain-specific session path
	'conflictStrategy'       => 'preserve', // How to handle existing sessions: 'preserve', 'replace'
];

// SMTP settings
$settings['smtp'] = [
	'host'      => '127.0.0.1',
	'port'      => '25',
	'secure'    => 'TLS',
	'from'      => '',
	'fromName'  => '',
	'to'        => '',
	'sendDelay' => 0,
];

// Push notification settings
$settings['pushnotif'] = [
	'pushoverAppToken'  => '',
	'pushoverUserKey'   => '',
	'pushoverGroupKey'  => '',
];

// Mailer settings (email sending system)
$settings['mailer'] = [
	// Only allow emails to these domains
	// 'whitelist' => [
	// '@yourcompany.com',
	// '@trustedclient.com',
	// ],
	'ratePerIp'       => 10,   // Max emails per IP per window
	'ratePerTemplate' => 50,   // Max emails per template per hour
	'rateWindow'      => 300,  // Time window in seconds (5 minutes)
];

$settings['imageworks'] = [
	'gatherLocation'      => true,
	'watermarksGallery'   => 'watermarks',
	'watermarkFontsDepot' => 'watermark-fonts',
	'defaults'            => [
		'fm' => 'jpg',
		'q'  => 92,
	],
	'presets' => [
		'small' => [
			'w'   => 300,
			'h'   => 200,
		],
		'small-crop' => [
			'w'   => 300,
			'h'   => 300,
			'fit' => 'crop-focalpoint',
		],
		'medium' => [
			'w'   => 600,
			'h'   => 400,
		],
		'medium-crop' => [
			'w'   => 600,
			'h'   => 600,
			'fit' => 'crop-focalpoint',
		],
	],
];

$settings['auth'] = [
	'enable'                  => true,
	'usePasskeys'             => true,
	'loginWith'               => 'both',  // 'email', 'id', or 'both'
	'collection'              => 'auth',
	'maxAttempts'             => 10,
	'downloadMaxAttempts'     => 25,  // Max password attempts for protected downloads
	'deniedTimeout'           => 7,
	'deniedDefaultRedirect'   => '/',
	'persistentLoginDays'     => 30,  // Number of days to keep user signed in when "Keep me signed in" is checked
	'forgotPasswordMailerId'  => '',  // Optional custom mailer ID for password reset emails (leave empty for default template)
	'resetTokenExpiry'        => 30,  // Minutes before password reset token expires
	// Allow-list of collection IDs that accept public (unauthenticated) user
	// registration via POST /admin/register/{collection}. Empty by default
	// because the default auth collection is operator-only — opt your member /
	// customer collections in explicitly. Bot abuse note: registrants are
	// auto-logged in after signup, so any visitor (or bot) that fills the form
	// gets a logged-in session in whatever default access group new users land
	// in. Gate this with a CAPTCHA, email verification, or manual approval if
	// the registered users will reach content that isn't safe to expose.
	'publicRegistration'      => [],
];

$settings['htmlclean'] = [
	'enabled'                => true,  // Set to false to disable HTML sanitization globally
	'allowed_css_properties' => [
		'color',
		'background-color',
		'font-size',
		'font-weight',
		'font-style',
		'font-family',
		'text-align',
		'text-decoration',
		'margin',
		'margin-left',
		'margin-right',
		'margin-top',
		'margin-bottom',
		'padding',
		'padding-left',
		'padding-right',
		'padding-top',
		'padding-bottom',
		'border',
		'line-height',
		'list-style-type',
		'width',
		'height',
		'max-width',
		'max-height',
		'display',
	],
	// 'allowed_tags' => ['p', 'strong', 'em'],
	// 'allowed_iframe_domains' => ['www.youtube.com']
];

$settings['presets'] = [
	'definitions' => '',
];

$settings['dashboard'] = [
	'pagination'        => 50, // Default pagination for dashboard tables
	'title'             => 'Total CMS Admin', // Browser title for admin dashboard pages
	'confirmCountdown'  => 3, // Seconds the confirm button stays disabled in destructive dialogs (0 = no countdown)
	// 'accent'            => '#4d91e2', // Dashboard accent color
	// 'keepIdOnDuplicate' => false, // Keep ID when duplicating objects (default: false - ID is cleared)
];

// License settings
// Only applicable for development and trial editions
$settings['license'] = [
	'simulateEdition' => null, // Set to 'lite', 'standard', or 'pro' to test edition restrictions
];

// Site Builder
$settings['builder'] = [
	'pagesCollection' => 'builder-pages', // Collection ID for page metadata
	'assetsPath'      => 'assets',        // Public assets directory relative to docroot
];

// https://www.php.net/manual/en/timezones.php
// DateTimeZone::listIdentifiers()
$settings['timezone'] = date_default_timezone_get();

return $settings;
