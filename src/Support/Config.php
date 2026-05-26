<?php

namespace TotalCMS\Support;

use TotalCMS\Domain\Property\Data\SlugData;

class Config
{
	public const LICENSE_API_URL = 'https://license.totalcms.co';

	public string $env                = 'prod';
	public string $template           = '';
	public string $datadir            = '';
	public string $tmpdir             = '';
	public string $cachedir           = '';
	public string $domain             = '';
	public string $siteName           = '';
	public string $url                = '';
	public string $api                = '';
	public string $locale             = '';
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
	public bool $sentry               = true;
	public string $appLogLevel        = 'info';
	public string $extensionsLogLevel = 'info';
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
	public array $pushnotif = [];
	/** @var array<string,mixed> */
	public array $presets  = [];
	public string $docroot = '';
	/** @var array<string,mixed> */
	public array $builder = [];
	/** @var array<string,mixed> */
	public array $mcp = [];
	/** @var array<string,mixed> */
	public array $oauth = [];
	/** @var array<string,mixed> */
	public array $search = [];

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,mixed> $settings
	 */
	public function __construct(array $settings)
	{
		$this->env                = $settings['env'] ?? 'prod';
		$this->template           = $settings['template'];
		$this->dashboard          = $settings['dashboard'];
		$this->datadir            = $settings['datadir'];
		$this->tmpdir             = $settings['tmpdir'];
		$this->cachedir           = $settings['cachedir'];
		$this->cache              = $settings['cache'];
		$this->logger             = $settings['logger'];
		$this->sentry             = (bool)($settings['sentry'] ?? true);
		$this->appLogLevel        = (string)($settings['appLogLevel'] ?? 'info');
		$this->extensionsLogLevel = (string)($settings['extensionsLogLevel'] ?? 'info');
		$this->error              = $settings['error'];
		$this->imageworks         = $settings['imageworks'];
		$this->domain             = $settings['domain'];
		$this->siteName           = (string)($settings['siteName'] ?? '');
		$this->url                = $settings['url'];
		$this->api                = $settings['api'];
		$this->i18n               = $this->normalizeI18nSettings($settings);
		// System locale: an explicit top-level `$settings['locale']` in tcms.php
		// is the advanced-override path for sites that need formatting to differ
		// from content default. Otherwise `$config->locale` mirrors the i18n
		// default, falling back to `en_US`.
		$this->locale             = isset($settings['locale']) && is_string($settings['locale']) && $settings['locale'] !== ''
			? $settings['locale']
			: ($this->i18n['default'] !== '' ? $this->i18n['default'] : 'en_US');
		$this->session            = $settings['session'];
		$this->auth               = $settings['auth'];
		$this->debug              = $settings['debug'];
		$this->notfound           = $settings['notfound'];
		$this->maxDownloadSize    = (int)($settings['maxDownloadSize'] ?? 2048);
		$this->timezone           = $settings['timezone'] ?? date_default_timezone_get();
		$this->docroot            = $settings['docroot'] ?? $_SERVER['DOCUMENT_ROOT'] ?? '';
		$this->htmlclean          = is_array($settings['htmlclean'] ?? null) ? $settings['htmlclean'] : [];
		$this->smtp               = is_array($settings['smtp'] ?? null) ? $settings['smtp'] : [];
		$this->mailer             = is_array($settings['mailer'] ?? null) ? $settings['mailer'] : [];
		$this->pushnotif          = is_array($settings['pushnotif'] ?? null) ? $settings['pushnotif'] : [];
		$this->builder            = is_array($settings['builder'] ?? null) ? $settings['builder'] : [];
		$this->mcp                = is_array($settings['mcp'] ?? null) ? $settings['mcp'] : [];
		$this->oauth              = is_array($settings['oauth'] ?? null) ? $settings['oauth'] : [];
		$this->search             = is_array($settings['search'] ?? null) ? $settings['search'] : [];

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

	public static function init(): self
	{
		return new Config(require PathResolver::packageRoot() . '/config/settings.php');
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
	 *    at top level. System locale stays at top-level `$settings['locale']`.
	 *
	 * 3. **Pre-3.5:** only `$settings['locale']` (system locale string)
	 *    exists; content-localization config is absent.
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
