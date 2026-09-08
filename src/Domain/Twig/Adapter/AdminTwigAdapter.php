<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\CacheSizingAdvisor;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\JobQueue\Data\JobQueueHealthData;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Service\BuilderTemplateRenderer;
use TotalCMS\Domain\Twig\Service\DashboardRenderer;
use TotalCMS\Domain\Twig\Service\JobQueueRenderer;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Twig sub-adapter for admin dashboard and management operations.
 *
 * Accessed in Twig as `cms.admin.*`.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
readonly class AdminTwigAdapter
{
	/**
	 * The dashboard, job-queue/cron and Site Builder template helpers are
	 * their own services (src/Domain/Twig/Service); this class exposes them as
	 * `cms.admin.*` and keeps only the small helpers that need nothing more
	 * than config, dev mode, the edition gates and the settings catalogue.
	 * The public diagnostics services are addressed by templates directly
	 * (`cms.admin.checker.*`, `cms.admin.logAnalyzer.*`, …).
	 */
	public function __construct(
		private Config $config,
		private DevModeManager $devModeManager,
		private CollectionEditionService $collectionEditionService,
		public CacheReporter $cacheReporter,
		private LicenseStatus $licenseStatus,
		public ServerChecker $checker,
		public LogAnalyzer $logAnalyzer,
		public ImageCacheService $imageCacheService,
		public CacheSizingAdvisor $cacheSizingAdvisor,
		private TranslationService $translator,
		private EditionFeatureService $editionFeatures,
		private DashboardRenderer $dashboard,
		private JobQueueRenderer $jobQueue,
		private BuilderTemplateRenderer $builderTemplates,
	) {
	}

	/**
	 * Job-queue health for the dashboard + Job Queue Manager warning. Returns a
	 * stalled flag (oldest waiting job past the threshold with no processor
	 * running) plus context for the message.
	 */
	public function dashboardJobQueueHealth(): JobQueueHealthData
	{
		return $this->dashboard->dashboardJobQueueHealth();
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @return array<string,int>
	 */
	public function dashboardStats(): array
	{
		return $this->dashboard->dashboardStats();
	}

	/**
	 * Get recent collections (top 10 by last updated).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardRecentCollections(): array
	{
		return $this->dashboard->dashboardRecentCollections();
	}

	/**
	 * Get collections that have no objects (might need attention).
	 * Always checks ALL collections, not just custom ones.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardEmptyCollections(): array
	{
		return $this->dashboard->dashboardEmptyCollections();
	}

	/**
	 * Get system status information.
	 *
	 * @return array<string,mixed>
	 */
	public function dashboardSystemStatus(): array
	{
		return $this->dashboard->dashboardSystemStatus();
	}

	/**
	 * Get recent objects across all collections (last 10).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardRecentObjects(): array
	{
		return $this->dashboard->dashboardRecentObjects();
	}

	/**
	 * Aggregated dashboard alerts — one entry per actionable condition.
	 *
	 * Returns `[]` when everything is healthy (all-clear). Each entry:
	 *   level    : 'warning'|'error'|'info'
	 *   message  : human-readable description
	 *   link     : ?string — relative admin path (no leading slash) or null
	 *   linkText : ?string — CTA label or null
	 *
	 * Never forces a cache refresh; reads cached status only so this is cheap
	 * on every page load.
	 *
	 * Edition simulation is intentionally excluded — the template owns that
	 * alert (it requires the raw simulation flag from the request context which
	 * is not cleanly available here without contorting the adapter).
	 *
	 * @return list<array{level:string,message:string,link:?string,linkText:?string}>
	 */
	public function dashboardAlerts(): array
	{
		return $this->dashboard->dashboardAlerts();
	}

	/**
	 * Dashboard automation list — all enabled automations combined with their
	 * latest run status.
	 *
	 * Each entry:
	 *   id         : automation object id
	 *   name       : human-readable name
	 *   trigger    : type of first trigger ('schedule'|'webhook'|'event'|'')
	 *   enabled    : always true (only enabled automations are returned)
	 *   lastResult : 'success'|'failed'|null (null = never run)
	 *   lastRunAt  : Unix timestamp of last run, or null
	 *   nextRunAt  : always null (computation deferred — non-trivial for cron)
	 *
	 * Returns `[]` when there are no enabled automations.
	 *
	 * @return list<array{id:string,name:string,trigger:string,enabled:bool,lastResult:?string,lastRunAt:?int,nextRunAt:?int}>
	 */
	public function dashboardAutomations(): array
	{
		return $this->dashboard->dashboardAutomations();
	}

	/**
	 * Get pending jobs info for display.
	 */
	public function jobQueuePendingInfo(): string
	{
		return $this->jobQueue->jobQueuePendingInfo();
	}

	/**
	 * Get failed jobs info for display.
	 */
	public function jobQueueFailedInfo(): string
	{
		return $this->jobQueue->jobQueueFailedInfo();
	}

	/**
	 * Prefix shared by every cron-displayable `tcms` command — the absolute
	 * PHP binary + the absolute path to the `tcms` executable, with an
	 * `APP_ENV=<value>` wedge only when a real APP_ENV is in play.
	 *
	 * We key off `appEnv` (the actual env-var value), NOT the merged `env`: when
	 * env came from the settings.json UI toggle there is no APP_ENV to reproduce,
	 * and the CLI resolves the same settings.json on its own. Injecting
	 * `APP_ENV=dev` there would wrongly load `config/local.dev.php` in the cron.
	 * Concrete commands (jobs:process, rss:import, …) append their own arguments.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function tcmsCommandPrefix(): string
	{
		return $this->jobQueue->tcmsCommandPrefix();
	}

	public function processJobQueueCommand(): string
	{
		return $this->jobQueue->processJobQueueCommand();
	}

	public function processAutomationsCommand(): string
	{
		return $this->jobQueue->processAutomationsCommand();
	}

	public function oauthSetupCommand(): string
	{
		return $this->jobQueue->oauthSetupCommand();
	}

	/**
	 * URL for an HTTP cron endpoint, with the token embedded.
	 *
	 * Absolute, because the whole point is pasting it into a host's cron box or
	 * an external cron service — a relative path is useless there. `url` carries
	 * the scheme and domain, `api` the base path, so a subdirectory install gets
	 * a working URL too. Not under the `/api` prefix: these routes are public.
	 *
	 * Calls tokenOrCreate(): the token comes into existence the first time an
	 * operator views the panel that needs it, which is why no setup command
	 * exists. Rendering the page is therefore what mints it — a deliberate trade
	 * against adding a route and a button purely to defer a file write that costs
	 * nothing and grants nothing on its own.
	 *
	 * @param string $task `jobs` or `automations`
	 */
	public function cronUrl(string $task): string
	{
		return $this->jobQueue->cronUrl($task);
	}

	/**
	 * Whether builder template editing is locked because templates are
	 * git-managed on this environment. Admin views use this to show a
	 * read-only banner and hide the save/delete controls.
	 */
	public function templatesLocked(): bool
	{
		return $this->builderTemplates->templatesLocked();
	}

	/**
	 * Group templates by folder for display in admin sidebar.
	 *
	 * @return array<string,array<array<string,string>>>
	 */
	public function templatesByFolder(): array
	{
		return $this->builderTemplates->templatesByFolder();
	}

	/**
	 * Get the builder file tree organized by category.
	 *
	 * @return array<string,list<array{id:string,path:string}>>
	 */
	public function builderFileTree(): array
	{
		return $this->builderTemplates->builderFileTree();
	}

	/**
	 * Get the builder file tree organized by category, with templates that
	 * contain forward slashes ("blog/post") nested into folders. Companion
	 * to {@see builderFileTree()} which returns the same data flat.
	 *
	 * Each node is either a folder `{type:'folder', name, children:[...]}`
	 * or a file `{type:'file', name, id, path}` where `id` is the relative
	 * template id (e.g. "blog/post") and `path` includes the category prefix
	 * (e.g. "pages/blog/post"). Folders sort before files; both alphabetical.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	public function builderNestedFileTree(): array
	{
		return $this->builderTemplates->builderNestedFileTree();
	}

	/**
	 * Check if a builder page route covers a collection's URL pattern.
	 *
	 * Returns the matching builder page data, or null if no match.
	 *
	 * @return array{id:string,title:string,route:string}|null
	 */
	public function builderRouteForCollection(string $collectionId): ?array
	{
		return $this->builderTemplates->builderRouteForCollection($collectionId);
	}


	/**
	 * Build an HTMX-powered quick action button.
	 *
	 * The route is relative to the site base — include the `/api` prefix for
	 * API routes (`/api/cache/clear`). Public routes (automation webhooks,
	 * imageworks) need no prefix (`/automations/my-automation`). A leading
	 * slash is optional.
	 *
	 * Options: method (default POST), confirm, reload (bool), redirect (string), class
	 *
	 * @param array<string,mixed> $options
	 */
	public function quickActionButton(string $label, string $route, array $options = []): string
	{
		$method   = strtolower((string)($options['method'] ?? 'POST'));
		$confirm  = (string)($options['confirm'] ?? '');
		$reload   = (bool)($options['reload'] ?? false);
		$redirect = (string)($options['redirect'] ?? '');
		$class    = (string)($options['class'] ?? '');

		$url = rtrim($this->config->api, '/') . '/' . ltrim($route, '/');
		$on  = ['error' => 'QuickAction.error(this, event)'];

		if ($redirect !== '') {
			$redirectUrl         = htmlspecialchars($redirect, ENT_QUOTES);
			$on['after:request'] = "QuickAction.redirect('$redirectUrl')";
		} elseif ($reload) {
			$on['after:request'] = 'QuickAction.reload()';
		}

		$attrs = HTMLUtils::htmxAttributes($url, $method, [
			'confirm' => $confirm,
			'on'      => $on,
		]);

		if ($class !== '') {
			$attrs['class'] = $class;
		}

		return HTMLUtils::element('a', $label, $attrs);
	}

	/**
	 * Get development mode status.
	 *
	 * @return array<string,mixed>
	 */
	public function devModeStatus(): array
	{
		return $this->devModeManager->getDevModeStatus();
	}

	/**
	 * Check if development mode is active.
	 */
	public function isDevModeActive(): bool
	{
		return $this->devModeManager->isDevModeActive();
	}

	/**
	 * Flattened docs menu for the quick-nav index. Reads the same
	 * resources/docs/menu.php that AdminDocsAction and the search-index
	 * builder consume, so quick-nav can never drift from the real doc tree.
	 * Walks both flat (`sub`) and nested (`groups`) top-level groups; the
	 * top-level group title becomes each entry's group label.
	 *
	 * @return list<array{group:string,title:string,path:string}>
	 */
	public function docsMenu(): array
	{
		$menuFile = PathResolver::packageRoot() . '/resources/docs/menu.php';
		if (!file_exists($menuFile)) {
			return [];
		}
		$menu = require $menuFile;
		if (!is_array($menu)) {
			return [];
		}

		$items = [];
		foreach ($menu as $group) {
			if (!is_array($group)) {
				continue;
			}
			$groupTitle = is_string($group['title'] ?? null) ? $group['title'] : '';

			$collect = function (mixed $sub) use (&$items, $groupTitle): void {
				if (!is_array($sub)) {
					return;
				}
				foreach ($sub as $page) {
					if (is_array($page) && isset($page['title'], $page['path'])
						&& is_string($page['title']) && is_string($page['path'])) {
						$items[] = [
							'group' => $groupTitle,
							'title' => $page['title'],
							'path'  => $page['path'],
						];
					}
				}
			};

			$collect($group['sub'] ?? null);

			if (is_array($group['groups'] ?? null)) {
				foreach ($group['groups'] as $subgroup) {
					if (is_array($subgroup)) {
						$collect($subgroup['sub'] ?? null);
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Canonical list of admin settings sections — the single source of truth for
	 * both the settings page sidebar (settings.twig) and the quick-nav index, so
	 * the two can never drift (mirrors how docsMenu() backs the docs nav).
	 *
	 * Keyed by section id (== the `settings/{id}` route == the settings schema
	 * id under resources/schemas/settings/). Each entry carries a resolved
	 * (translated) label + description. Ordered alphabetically by label so the
	 * sidebar scales as sections are added.
	 *
	 * Edition/license-gated sections are appended only when available: `oauth`
	 * needs the OAuth Server feature, `license` needs edition simulation — the
	 * same gating settings.twig previously did inline.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public function settingsSections(): array
	{
		$t = fn (string $key): string => $this->translator->trans($key, [], 'admin');

		$sections = [
			'general'    => ['label' => $t('settings.general'),           'description' => $t('settings.general_desc')],
			'auth'       => ['label' => $t('settings.authentication'),    'description' => $t('settings.authentication_desc')],
			'cache'      => ['label' => $t('settings.cache'),             'description' => $t('settings.cache_desc')],
			'dashboard'  => ['label' => $t('settings.dashboard'),         'description' => $t('settings.dashboard_desc')],
			'extensions' => ['label' => $t('settings.extensions'),        'description' => $t('settings.extensions_desc')],
			'htmlclean'  => ['label' => $t('settings.html_sanitization'), 'description' => $t('settings.html_sanitization_desc')],
			'i18n'       => ['label' => $t('settings.i18n'),              'description' => $t('settings.i18n_desc')],
			'imageworks' => ['label' => $t('settings.imageworks'),        'description' => $t('settings.imageworks_desc')],
			'mailer'     => ['label' => $t('settings.mailer'),            'description' => $t('settings.mailer_desc')],
			'mcp'        => ['label' => $t('settings.mcp'),               'description' => $t('settings.mcp_desc')],
			'presets'    => ['label' => $t('settings.presets'),           'description' => $t('settings.presets_desc')],
			'smtp'       => ['label' => $t('settings.smtp'),              'description' => $t('settings.smtp_desc')],
			'search'     => ['label' => $t('settings.search'),            'description' => $t('settings.search_desc')],
			'sync'       => ['label' => $t('settings.sync'),              'description' => $t('settings.sync_desc')],
			'xmlrpc'     => ['label' => $t('settings.xmlrpc'),            'description' => $t('settings.xmlrpc_desc')],
			// Builder predates the settings translation keys and ships English-only.
			'builder'    => ['label' => 'Builder',                        'description' => 'Site Builder stub generation settings'],
		];

		if ($this->editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			$sections['oauth'] = ['label' => $t('settings.oauth'), 'description' => $t('settings.oauth_desc')];
		}

		if ($this->licenseStatus->canSimulateEdition()) {
			$sections['license'] = ['label' => $t('settings.license_simulator'), 'description' => $t('settings.license_simulator_desc')];
		}

		// Sort by visible label (case-insensitive), preserving the id keys the
		// templates use for routing + active-state matching.
		uasort($sections, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $sections;
	}
	// -------------------------
	// Pretty URL Rule Generators
	// -------------------------

	public function apacheRule(string $url, string $collection = 'Collection'): string
	{
		$path  = strval(parse_url($url, PHP_URL_PATH));
		$start = $this->startPathForUrl($url);

		return <<<HTACCESS
# Total CMS Pretty URL Rewrites for $collection
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^$start([\w-]+)/?$ $path?id=$1 [L,QSA]
HTACCESS;
	}

	public function nginxRule(string $url, string $collection = 'Collection'): string
	{
		$path  = strval(parse_url($url, PHP_URL_PATH));
		$start = $this->startPathForUrl($url);

		return <<<NGINX
# Total CMS Pretty URL Rewrites for {$collection}
rewrite ^/{$start}([\w-]+)/?\$ /{$path}?id=\$1 last;
NGINX;
	}

	private function startPathForUrl(string $url): string
	{
		$path  = strval(parse_url($url, PHP_URL_PATH));
		$start = $path;

		if (str_ends_with($path, 'php')) {
			$start = dirname($path) . '/';
		}
		if (!str_ends_with($start, '/')) {
			$start .= '/';
		}

		return ltrim($start, '/');
	}


	/**
	 * Get collections that are inaccessible due to edition restrictions.
	 *
	 * @return array<CollectionData>
	 */
	public function inaccessibleCollections(): array
	{
		return $this->collectionEditionService->getInaccessibleCollections();
	}

	/**
	 * Get schemas that are inaccessible due to edition restrictions.
	 *
	 * @return array<string>
	 */
	public function inaccessibleSchemas(): array
	{
		return $this->collectionEditionService->getInaccessibleSchemas();
	}
}
