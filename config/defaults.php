<?php

// Configure defaults for the whole application.

// Error reporting
error_reporting(0);
ini_set('display_errors', '0');

if (PHP_SAPI === 'cli') {
	// The CLI/cron has no public HTTP response to protect, so suppressing
	// errors here buys nothing — it just turns a fatal into a silent exit 255
	// that's invisible on cron (and there's no ?debugstart query string to
	// flip). Surface problems to STDERR so operators and cron logs see them;
	// stderr (not stdout) keeps command output — tables and --json — clean.
	// Deprecations are masked so routine runs don't spam the logs.
	error_reporting(E_ALL & ~E_DEPRECATED);
	ini_set('display_errors', 'stderr');
} elseif (isset($_GET['debugstart'])) {
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
$settings['env'] = 'prod';

// Internationalization config.
//
// `default`   — Single canonical locale for the site. Drives runtime formatting
//               (PHP intl, CakePHP I18n, Faker — dates, numbers, currency), the
//               admin UI translation language, and (Pro) the default tab on
//               localized content fields plus the field-level fallback.
//               `$config->locale` mirrors this value.
// `available` — Pro only. Flat list of locale codes (from LocaleRegistry) used
//               by localized field types. Empty list = field types are disabled.
//               Order matters: the Twig helper uses it for region fall-down
//               (e.g., a request for bare `en` returns the first matching `en_*`).
//
// Codes use mixed-case POSIX format (en_US, pt_BR) to match PHP intl, CakePHP I18n,
// Faker, and T3's existing admin translation file naming.
//
// Example (Pro):
//   $settings['i18n'] = [
//       'default'   => 'en_US',
//       'available' => ['en_US', 'de', 'ar'],
//   ];
$settings['i18n'] = [
	'default'   => 'en_US',
	'available' => [],
];

// Auto-detect the site domain. Prefer the request Host header, but when the app
// sits behind Docker / a reverse proxy that doesn't forward Host, HTTP_HOST is a
// loopback or bridge IP (127.0.0.1, 172.x, localhost). In that case fall back to
// SERVER_NAME — the web server's configured server_name — which is usually the
// real domain. Operators can always override `domain` in config/tcms.php.
$detectedHost = $_SERVER['HTTP_HOST'] ?? '';
$serverName   = $_SERVER['SERVER_NAME'] ?? '';

if (($detectedHost === '' || TotalCMS\Support\Config::isNonRoutableHost($detectedHost))
	&& $serverName !== '' && !TotalCMS\Support\Config::isNonRoutableHost($serverName)
) {
	$detectedHost = $serverName;
}

$settings['domain']   = $detectedHost !== '' ? $detectedHost : ($serverName !== '' ? $serverName : 'unknown');

// Human-readable site name (e.g. "Joe's Bistro"). When blank, T3 falls back
// to dashboard.title (if customized) and then $domain via Config::displayName().
// Used by the MCP server's serverInfo and get_site_info tool; future features
// (RSS, sitemap, setup wizard, email From, PWA manifest, JumpStart filenames)
// adopt the same helper. See docs/planning/site-name.md.
$settings['siteName'] = '';

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
// installs (e.g. https://example.com/cms/), this is the subpath ("/cms").
// For the Stacks layout a docroot rewrite hides "public/" from the URL even
// though SCRIPT_NAME still points inside it, so a naive dirname(SCRIPT_NAME)
// wrongly keeps "/public". BasePath::resolve() cross-checks SCRIPT_NAME
// against the request path to strip the right depth — and shares that logic
// with BasePathMiddleware so link-building and Slim routing always agree.
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if ($scriptName === '' || !str_starts_with($scriptName, '/')) {
	// CLI, cli-server (after the bootstrap workaround rewrites
	// SCRIPT_NAME to a basename), or other unusual SAPIs.
	$settings['api'] = '';
} else {
	$requestPath     = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
	$settings['api'] = TotalCMS\Support\BasePath::resolve($scriptName, $requestPath);
}

$settings['debug'] = false; // Set to true for development

// Cache configuration
// Priority: APCu > Redis > Memcached > Filesystem (optimized for single-server deployments)
$settings['cache'] = [
	'apcu'        => true,
	'filesystem'  => true,
	'redis'       => true,
	'memcached'   => true,
	// Output (fragment) caching via the {% cache %} Twig tag.
	'fragments'   => true, // master on/off switch for {% cache %}
	'fragmentTtl' => 3600, // default fragment lifetime (seconds) when ttl= is omitted
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

// Logger settings.
// `path` is intentionally empty here: the default is layout-aware AND
// datadir-relative, and tcms.php can override `datadir` after this file
// loads (the same load-order trap as the OAuth key paths — see
// local.test.php). Config resolves it post-merge:
//   - Composer installs → <projectRoot>/logs (survives `composer update`)
//   - Zip installs      → <datadir>/.system/logs (survives app-directory
//     replacement during updates; the old <app>/logs was orphaned into the
//     backup on every update)
// Set `path` explicitly in tcms.php to override both.
$settings['logger'] = [
	'name'        => 'totalcms',
	'path'        => '',
	'filename'    => 'totalcms.log',
	'level'       => Monolog\Level::Info,
	'maxFiles'    => 10,
	'permissions' => 0775,
];

// Per-channel log level overrides controlled from /admin/settings (general).
// Names are PSR-3 strings: debug, info, notice, warning, error, critical, alert, emergency.
// When set, these override $settings['logger']['level'] for the matching log file.
$settings['appLogLevel']        = 'info';

// Extension settings (/admin/settings/extensions). Namespaced under `extensions`,
// read in code as $config->extensions['logLevel'], ['profileSampleRate'], etc.
$settings['extensions'] = [
	'logLevel'             => 'info',
	'profileSampleRate'    => 50,
	'budgetMsPerExtension' => 200,
	'budgetMsPerStack'     => 500,
];

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
	'verificationMailerId'    => '',  // Optional custom mailer ID for email verification (leave empty for default template)
	'verificationTokenExpiry' => 1440,  // Minutes before email verification token expires (default: 24 hours)
	// Allow-list of collection IDs that accept public (unauthenticated) user
	// registration via POST /admin/register/{collection}. Empty by default
	// because the default auth collection is operator-only — opt your member /
	// customer collections in explicitly. Whether new users are auto-logged in
	// or sent a verification email is controlled per-collection via the
	// "Require Email Verification" toggle on the collection's settings page.
	// Bot abuse note: with auto-login enabled, any visitor (or bot) that fills
	// the form gets a logged-in session in whatever default access group new
	// users land in. With verification required, the account is created
	// inactive — bots can still fill the table with junk records, so gate
	// this with a CAPTCHA or rate limit even when verification is on.
	'publicRegistration'      => [],
	// Access group id force-assigned to users created via public registration.
	// Empty = no access group (safest default; registrants get no gated access
	// until an operator grants it). Set to a member/customer group id to grant
	// new signups that group. A `groups` value submitted in the form is always
	// ignored — only this server-side value is honored.
	'publicRegistrationGroup' => '',
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

// MCP (Model Context Protocol) Server
// Exposes /mcp and /.well-known/mcp.json. Pro+ only — see EditionFeature::MCP_SERVER.
// When `enabled` is false the endpoint returns 404 and discovery reports disabled.
// `publicAccess` is the master switch for the anonymous persona; individual collections
// must additionally opt in via their `mcp.access` schema setting (Phase 1).
// `toolPrefix` is an optional namespace prepended to every tool name — useful when
// running multiple T3 sites in the same AI agent (e.g. 'bistro' → bistro_list_collections).
$settings['mcp'] = [
	'enabled'              => true,
	'publicAccess'         => false,
	'allowedOrigins'       => [],
	'publicIpPerMinute'    => 60,
	'toolPrefix'           => '',
	// Operator kill switch for resource subscriptions. When off, the SDK falls
	// back to its per-session-only default — resources/subscribe still works
	// but T3 won't push notifications/resources/updated when content changes.
	'subscriptionsEnabled' => true,
];

// Search providers — Phase 5.
// activeProvider: 'text' (built-in) or any registered provider id (e.g. 'algolia').
// indexOnSave: when true, T3 pushes object.created/updated events to the active
//   provider's index() method. Disable during bulk imports to avoid embedding-API
//   load; re-enable + run `tcms search:reindex` after.
$settings['search'] = [
	'activeProvider' => 'text',
	'indexOnSave'    => true,
];

// OAuth 2.0 Server
// RSA key pair for signing access tokens (JWTs) and encrypting auth code payloads.
// Generate with: tcms oauth:setup (creates keys at the paths below).
// accessTokenTtl / refreshTokenTtl / authCodeTtl are PHP DateInterval specs.
$settings['oauth'] = [
	'signingKeyPath'      => $settings['datadir'] . '/.system/oauth-keys/private.key',
	'publicKeyPath'       => $settings['datadir'] . '/.system/oauth-keys/public.key',
	'accessTokenTtl'      => 'PT1H',   // 1 hour
	'refreshTokenTtl'     => 'P30D',   // 30 days
	'authCodeTtl'         => 'PT10M',  // 10 minutes
	'dynamicRegistration' => false,    // RFC 7591 self-registration — off by default (unauthenticated endpoint); set true to let MCP clients self-register
];

// https://www.php.net/manual/en/timezones.php
// DateTimeZone::listIdentifiers()
$settings['timezone'] = date_default_timezone_get();

return $settings;
