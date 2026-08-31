<?php

namespace TotalCMS\Support;

use TotalCMS\Domain\Property\Data\SlugData;

class Config
{
	public const LICENSE_API_URL = 'https://license.totalcms.co';

	public string $env        = 'prod';
	public string $appEnv     = ''; // Actual `APP_ENV` env-var value
	public string $template   = '';
	public string $datadir    = '';
	public string $tmpdir     = '';
	public string $cachedir   = '';
	public string $domain     = '';
	public string $siteName   = '';
	public string $url        = '';
	public string $api        = '';
	public string $locale     = '';
	/**
	 * Internationalization config bucket.
	 *
	 * @var array{default: string, available: array<int,array<string,string>>}
	 */
	public array $i18n                = ['default' => '', 'available' => []];
	public string $timezone           = '';
	public string $notfound           = '';
	public int $maxDownloadSize       = 2048;
	public bool $debug                = false;
	/** 'auto' | 'always' | 'never' — see ClientIpResolver. */
	public string $trustProxyHeaders  = 'auto';
	public bool $sentry               = true;
	public string $appLogLevel        = 'info';
	/** @var array<string,mixed> */
	public array $cache = [];
	/** @var array<string,mixed> */
	public array $session = [];
	/** @var array<string,mixed> */
	public array $logger = [];
	/** @var array<string,mixed> */
	public array $error = [];
	/** @var array<string,mixed> */
	public array $imageworks = [];
	/** @var array<string,mixed> */
	public array $auth = [];
	/** @var array<string,mixed> */
	public array $htmlclean = [];
	/** @var array<string,mixed> */
	public array $dashboard = [];
	/** @var array<string,mixed> */
	public array $smtp = [];
	/** @var array<string,mixed> */
	public array $mailer = [];
	/** @var array<string,mixed> */
	public array $presets  = [];
	public string $docroot = '';
	/** Project root (the composer project / install root; `PathResolver::projectRoot()`). */
	public string $root = '';
	/** @var array<string,mixed> */
	public array $builder = [];
	/** @var array<string,mixed> */
	public array $extensions = [];
	/** @var array<string,mixed> */
	public array $mcp = [];
	/** @var array<string,mixed> */
	public array $oauth = [];
	/** @var array<string,mixed> */
	public array $search = [];
	/** @var array<string,mixed> */
	public array $automations = [];
	/** @var array<string,mixed> */
	public array $xmlrpc = [];

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,mixed> $settings
	 */
	public function __construct(array $settings)
	{
		$this->env                = $settings['env'] ?? 'prod';
		$this->appEnv             = (string)($settings['appEnv'] ?? '');
		$this->trustProxyHeaders  = (string)($settings['trustProxyHeaders'] ?? 'auto');
		$this->template           = $settings['template'];
		$this->dashboard          = $settings['dashboard'];
		$this->datadir            = $settings['datadir'];
		$this->tmpdir             = $settings['tmpdir'];
		$this->cachedir           = $settings['cachedir'];
		$this->cache              = $settings['cache'];
		$this->logger             = $settings['logger'];
		// Layout-aware log directory, resolved AFTER the tcms.php merge so a
		// datadir override is respected (defaults.php can't know the final
		// datadir — see the logger block there). An explicit logger.path in
		// tcms.php wins. Composer installs keep logs at the project root,
		// which composer update never touches; zip installs write into the
		// datadir so logs survive the app-directory swap during updates.
		if (($this->logger['path'] ?? '') === '') {
			$this->logger['path'] = PathResolver::isComposerInstall()
				? PathResolver::projectRoot() . '/logs'
				: $this->systemDir() . '/logs';
		}
		$this->sentry             = (bool)($settings['sentry'] ?? true);
		$this->appLogLevel        = (string)($settings['appLogLevel'] ?? 'info');
		$this->error              = $settings['error'];
		$this->imageworks         = $settings['imageworks'];
		$this->domain             = $settings['domain'];
		$this->siteName           = (string)($settings['siteName'] ?? '');
		$this->url                = $settings['url'];
		$this->api                = $settings['api'];
		$this->i18n               = $this->normalizeI18nSettings($settings);
		// System locale always mirrors the i18n default (Settings →
		// Internationalization → Default Locale), falling back to `en_US`.
		// A top-level `$settings['locale']` is deliberately ignored — it was the
		// storage key for the old General-settings locale field, and honouring
		// it caused orphaned values to silently shadow the new i18n default.
		$this->locale             = $this->i18n['default'] !== '' ? $this->i18n['default'] : 'en_US';
		$this->session            = $settings['session'];
		$this->auth               = $settings['auth'];
		$this->debug              = $settings['debug'];
		$this->notfound           = $settings['notfound'];
		$this->maxDownloadSize    = (int)($settings['maxDownloadSize'] ?? 2048);
		$this->timezone           = $settings['timezone'] ?? date_default_timezone_get();
		$this->docroot            = $settings['docroot'] ?? $_SERVER['DOCUMENT_ROOT'] ?? '';
		$this->root               = (string)($settings['root'] ?? PathResolver::projectRoot());
		$this->htmlclean          = is_array($settings['htmlclean'] ?? null) ? $settings['htmlclean'] : [];
		$this->smtp               = is_array($settings['smtp'] ?? null) ? $settings['smtp'] : [];
		$this->mailer             = is_array($settings['mailer'] ?? null) ? $settings['mailer'] : [];
		$this->builder            = is_array($settings['builder'] ?? null) ? $settings['builder'] : [];
		$this->extensions         = is_array($settings['extensions'] ?? null) ? $settings['extensions'] : [];
		$this->mcp                = is_array($settings['mcp'] ?? null) ? $settings['mcp'] : [];
		$this->oauth              = is_array($settings['oauth'] ?? null) ? $settings['oauth'] : [];
		$this->search             = is_array($settings['search'] ?? null) ? $settings['search'] : [];
		$this->automations        = is_array($settings['automations'] ?? null) ? $settings['automations'] : [];
		$this->xmlrpc             = is_array($settings['xmlrpc'] ?? null) ? $settings['xmlrpc'] : [];

		$presets               = $settings['presets'] ?? [];
		$this->presets         = is_array($presets['presetsettings'] ?? null) ? $presets['presetsettings'] : [];

		date_default_timezone_set($this->timezone);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return get_object_vars($this);
	}

	/**
	 * Absolute path to the install's private system directory,
	 * `<datadir>/.system`. This is the per-install home for internal state that
	 * must survive updates and stay out of the public collection space: logs,
	 * extension state/settings/data, setup state, dev-mode flag, etc. It lives
	 * under `.system`, which ships deny-all, so nothing here is web-reachable.
	 *
	 * Always derive system-state paths from this rather than `sys_get_temp_dir()`
	 * — a global temp path collides across tenants on shared hosting.
	 *
	 * Computed (not a stored property) so it tracks `datadir` even when that is
	 * set after construction (e.g. tests building Config via reflection).
	 */
	/**
	 * Whether the authentication system is switched on.
	 *
	 * Twenty call sites read `$config->auth['enable'] === false` directly. The
	 * key is in defaults.php, so on a normally-built Config it is always there
	 * — but a Config assembled programmatically (an extension, a test, a
	 * partial settings array) can omit it, and the bare read then emits
	 * "Undefined array key". It fails secure, evaluating to "auth stays on",
	 * but on installs that promote warnings to exceptions it throws instead:
	 * from AuthMiddleware that is a 500 on every authenticated request.
	 *
	 * Absent means enabled, matching the shipped default. Only an explicit
	 * `false` disables, as before — a truthy string or 0 does not.
	 */
	public function authEnabled(): bool
	{
		return ($this->auth['enable'] ?? true) !== false;
	}

	public function systemDir(): string
	{
		return rtrim($this->datadir, '/\\') . '/.system';
	}

	/**
	 * Canonical human-readable site identity. Used by features that surface
	 * the site to humans or AI agents (MCP serverInfo, future RSS feed title,
	 * sitemap chrome, PWA manifest, etc.) instead of falling back to the bare
	 * domain or co-opting the admin dashboard title for double duty.
	 *
	 * Fallback chain (first non-empty wins):
	 *   1. `siteName` — operator's explicit choice
	 *   2. `dashboard.title` — only if customized away from the default
	 *      "Total CMS Admin" (which is meaningless as a site name)
	 *   3. `domain` — last resort, always present
	 *
	 * See docs/planning/site-name.md for the catalog of future adopters.
	 */
	public function displayName(): string
	{
		if ($this->siteName !== '') {
			return $this->siteName;
		}

		$dashboardTitle = (string)($this->dashboard['title'] ?? '');
		if ($dashboardTitle !== '' && $dashboardTitle !== 'Total CMS Admin') {
			return $dashboardTitle;
		}

		return $this->domain;
	}

	/**
	 * Browser title for admin dashboard pages.
	 *
	 * Fallback chain (first match wins):
	 *   1. `dashboard.title` — only if customized away from the shipped
	 *      default "Total CMS Admin" (same sentinel rule as displayName())
	 *   2. `siteName` + " Admin" — so a named site gets a branded tab title
	 *      with zero configuration
	 *   3. "Total CMS Admin"
	 */
	public function adminTitle(): string
	{
		$dashboardTitle = (string)($this->dashboard['title'] ?? '');
		if ($dashboardTitle !== '' && $dashboardTitle !== 'Total CMS Admin') {
			return $dashboardTitle;
		}

		if ($this->siteName !== '') {
			return $this->siteName . ' Admin';
		}

		return 'Total CMS Admin';
	}

	/**
	 * Slugified variant of {@see displayName()} — safe for filenames, URL
	 * slugs, and any context that needs a single token without spaces or
	 * punctuation. Same fallback chain as `displayName()`, then run through
	 * `SlugData::slugify()` (which strips diacritics and non-alphanumerics).
	 *
	 * Example: `Joe's Bistro` → `joes-bistro`; `example.com` → `example-com`.
	 */
	public function displaySlug(): string
	{
		return SlugData::slugify($this->displayName());
	}

	/**
	 * Whether a host string looks non-routable — i.e. `localhost`, a `.localhost`
	 * subdomain, or a bare IP address (any port/userinfo is stripped first).
	 *
	 * `domain` is auto-detected from the request `Host` header, so inside Docker
	 * or behind a reverse proxy that doesn't forward Host it silently becomes
	 * something like `127.0.0.1` or a `172.x` bridge IP. A licensed production
	 * domain is never a bare IP, so this is a reliable signal that the operator
	 * needs to set `domain` explicitly in config/tcms.php. Used by license
	 * diagnostics and the MCP discovery endpoint.
	 */
	public static function isNonRoutableHost(string $host): bool
	{
		$host = strtolower(trim($host));
		if ($host === '') {
			return false;
		}

		// Strip userinfo (user:pass@host)
		if (str_contains($host, '@')) {
			$host = substr($host, strrpos($host, '@') + 1);
		}

		// Strip the port. Bracketed IPv6 — [::1]:8080 → ::1
		if (str_starts_with($host, '[')) {
			$host = (string)preg_replace('/^\[(.+?)\](?::\d+)?$/', '$1', $host);
		} elseif (substr_count($host, ':') === 1) {
			$host = substr($host, 0, (int)strpos($host, ':'));
		}

		if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
			return true;
		}

		return filter_var($host, FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Fully-qualified, base-path-aware MCP endpoint URL.
	 *
	 * The server is mounted at `<base>/mcp`, where `<base>` is the install's
	 * subpath (`$api` — empty at the domain root, `/cms` for a subfolder,
	 * `/rw_common/plugins/stacks/tcms` for Stacks). Operators routinely get this
	 * wrong by hand — they reach for `domain.com/mcp` and drop the base path —
	 * so this is the single source of truth shared by the discovery endpoint and
	 * the admin connection panel.
	 *
	 * Pass an explicit `$baseUrl` (scheme://host[:port]) to honour the host the
	 * caller actually reached us on; the discovery endpoint forwards the request
	 * authority so proxied hosts resolve. Defaults to the configured site URL.
	 */
	public function mcpEndpoint(?string $baseUrl = null): string
	{
		return rtrim($baseUrl ?? $this->url, '/') . $this->api . '/mcp';
	}

	/**
	 * Memoized result of requiring `config/settings.php`, with the mutable
	 * globals that file reads recorded alongside it.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $settings    = null;
	private static string $settingsKey = '';

	/**
	 * Build a Config from the app settings.
	 *
	 * Returns a NEW instance every call — callers mutate what they get back
	 * (DataPathInstaller syncs `datadir`; a lot of tests poke `env`), so the
	 * instance itself must never be shared.
	 *
	 * What *is* memoized is the settings array behind it, because requiring
	 * `config/settings.php` costs ~200us — it pulls in defaults.php, probes
	 * several tcms.php locations, and merges settings.json off disk — while
	 * constructing the Config from an in-hand array costs ~4us. DateData,
	 * StringData and CodeData all call init() from their constructors, so that
	 * require was being charged per property on every object hydration: a
	 * gallery rebuilt once per image in a Twig loop spent seconds here alone.
	 *
	 * The memo is keyed on the mutable globals settings.php actually reads, so
	 * a test (or the setup wizard) repointing DOCUMENT_ROOT or APP_ENV still
	 * gets a fresh read. Call reset() when the files themselves change.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public static function init(): self
	{
		$docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		$appEnv  = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
		$key     = $docroot . '|' . (is_string($appEnv) ? $appEnv : '');

		$settings = self::$settings;

		if ($settings === null || self::$settingsKey !== $key) {
			/** @var array<string,mixed> $settings */
			$settings          = require PathResolver::packageRoot() . '/config/settings.php';
			self::$settings    = $settings;
			self::$settingsKey = $key;
		}

		return new Config($settings);
	}

	/**
	 * Drop the memoized settings so the next init() re-reads them from disk.
	 * Needed whenever the underlying files change — see SettingsSaver.
	 */
	public static function reset(): void
	{
		self::$settings    = null;
		self::$settingsKey = '';
	}

	/**
	 * Resolve i18n settings from one of three accepted shapes (newest wins):
	 *
	 * 1. **Canonical (3.5):** `$settings['i18n']` is a bucket with
	 *    `default` / `available` keys. `available` is a flat list of registry
	 *    codes — expanded into the full `[{code, label, dir}, ...]` shape via
	 *    `LocaleRegistry::expand()`. A `locale` sub-key is also tolerated for
	 *    backwards compat with the in-development i18n.locale layout — its
	 *    value is folded into `default` when `default` is empty.
	 *
	 * 2. **Sliver shape (pre-bucket-rename):** `$settings['locales']` (flat
	 *    array OR pre-expanded dict-of-dicts) + `$settings['defaultLocale']`
	 *    at top level.
	 *
	 * 3. **Pre-3.5:** no localization config at all; `$config->locale` falls
	 *    back to `en_US`.
	 *
	 * A top-level `$settings['locale']` (the old General-settings storage key) is
	 * ignored everywhere — `$config->locale` derives solely from the i18n default.
	 *
	 * @param array<string,mixed> $settings
	 *
	 * @return array{default: string, available: array<int,array<string,string>>}
	 */
	private function normalizeI18nSettings(array $settings): array
	{
		$bucket = $settings['i18n'] ?? null;
		if (is_array($bucket)) {
			$default = (string)($bucket['default'] ?? '');
			// In-flight compat: a stray `i18n.locale` from before the
			// consolidation gets folded into `default` when default is empty.
			if ($default === '' && isset($bucket['locale']) && is_string($bucket['locale'])) {
				$default = $bucket['locale'];
			}

			return [
				'default'   => $default,
				'available' => \TotalCMS\Domain\Locale\LocaleRegistry::normalize($bucket['available'] ?? []),
			];
		}

		// Legacy flat-key shape (3.5 sliver pre-rename). Fold into the bucket.
		return [
			'default'   => (string)($settings['defaultLocale'] ?? ''),
			'available' => \TotalCMS\Domain\Locale\LocaleRegistry::normalize($settings['locales'] ?? []),
		];
	}
}
