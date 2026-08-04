<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Util\NestedFileTree;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\CacheSizingAdvisor;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Cron\Service\CronTokenProvider;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Data\JobQueueHealthData;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Data\LicenseStatusData;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Update\Service\UpdateChecker;
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
	public function __construct(
		private Config $config,
		private AuthTwigAdapter $auth,
		private CollectionLister $collectionLister,
		private SchemaLister $schemaLister,
		private TemplateLister $templateLister,
		private JobManager $jobManager,
		private DevModeManager $devModeManager,
		private CollectionEditionService $collectionEditionService,
		public CacheReporter $cacheReporter,
		private LicenseStatus $licenseStatus,
		private IndexReader $indexReader,
		public ServerChecker $checker,
		public LogAnalyzer $logAnalyzer,
		public ImageCacheService $imageCacheService,
		public CacheSizingAdvisor $cacheSizingAdvisor,
		private UpdateChecker $updateChecker,
		private BuilderConfigService $builderConfig,
		private CollectionFetcher $collectionFetcher,
		private \TotalCMS\Domain\Builder\Service\BuilderTemplatePaths $paths,
		private JobQueueHealth $jobQueueHealth,
		private TranslationService $translator,
		private EditionFeatureService $editionFeatures,
		private AutomationLoader $automationLoader,
		private AutomationRunReader $automationRunReader,
		private ExtensionStateRepository $extensionStateRepository,
		private CronTokenProvider $cronTokens,
	) {
	}

	/**
	 * Job-queue health for the dashboard + Job Queue Manager warning. Returns a
	 * stalled flag (oldest waiting job past the threshold with no processor
	 * running) plus context for the message.
	 */
	public function dashboardJobQueueHealth(): JobQueueHealthData
	{
		return $this->jobQueueHealth->status();
	}

	/**
	 * Whether builder template editing is locked because templates are
	 * git-managed on this environment. Admin views use this to show a
	 * read-only banner and hide the save/delete controls.
	 */
	public function templatesLocked(): bool
	{
		return $this->paths->locked();
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
		$phpPath = defined(PHP_BINARY) ? PHP_BINARY : 'php';
		// Composer installs must use the generated bin proxy (vendor/bin/tcms); zip
		// installs use the shipped resources/bin/tcms. PathResolver::tcmsBinary()
		// picks the right one from the package path structure — unlike
		// isComposerInstall(), it does not depend on TCMS_PROJECT_ROOT being
		// defined, which it isn't during a plain web request.
		$command = PathResolver::tcmsBinary();

		// Quote path if it contains spaces
		$quotedCommand = str_contains($command, ' ') ? '"' . $command . '"' : $command;

		$envPrefix = $this->config->appEnv !== '' ? 'APP_ENV=' . $this->config->appEnv . ' ' : '';

		return sprintf('%s%s %s', $envPrefix, $phpPath, $quotedCommand);
	}

	public function processJobQueueCommand(): string
	{
		return $this->tcmsCommandPrefix() . ' jobs:process';
	}

	public function processAutomationsCommand(): string
	{
		return $this->tcmsCommandPrefix() . ' automations:process';
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
		return sprintf(
			'%s%s/cron/%s?token=%s',
			rtrim($this->config->url, '/'),
			$this->config->api,
			$task,
			$this->cronTokens->tokenOrCreate()
		);
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
	 * Get pending jobs info for display.
	 */
	public function jobQueuePendingInfo(): string
	{
		$pendingJobs = $this->jobManager->getPendingJobs();

		if ($pendingJobs === []) {
			return '';
		}

		$rows = '';
		foreach ($pendingJobs as $job) {
			$payload  = json_decode($job->payload, true);
			$objectId = $payload['id'] ?? 'N/A';

			$rows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars($job->type),
				htmlspecialchars($job->collection),
				htmlspecialchars((string)$objectId),
				htmlspecialchars($this->formatJobDate($job->createdAt))
			);
		}

		return sprintf(
			'<section class="jobqueue-preview-section">
				<h3>Pending Jobs</h3>
				<div class="jobqueue-table-wrapper">
					<table class="jobqueue-preview pending-jobs cms-colors">
						<thead>
							<tr>
								<th>Type</th>
								<th>Collection</th>
								<th>Object ID</th>
								<th>Created</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</section>',
			$rows
		);
	}

	/**
	 * Job timestamps are stored UTC (SQLite CURRENT_TIMESTAMP). Display them in
	 * the site's configured timezone so the queue manager shows local time.
	 */
	private function formatJobDate(string $utcDate): string
	{
		return \TotalCMS\Domain\Property\Data\DateData::utcToTimezone($utcDate, $this->config->timezone);
	}

	/**
	 * Get failed jobs info for display.
	 */
	public function jobQueueFailedInfo(): string
	{
		$failedJobs = $this->jobManager->getFailedJobs();

		if ($failedJobs === []) {
			return '';
		}

		$rows = '';
		foreach ($failedJobs as $job) {
			$payload  = json_decode($job->payload, true);
			$objectId = $payload['id'] ?? 'N/A';

			// Truncate error message for display
			$errorSnippet = $job->lastError;
			if (strlen($errorSnippet) > 100) {
				$errorSnippet = substr($errorSnippet, 0, 100) . '...';
			}

			$rows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td title="%s">%s</td></tr>',
				htmlspecialchars($job->type),
				htmlspecialchars($job->collection),
				htmlspecialchars((string)$objectId),
				htmlspecialchars(strval($job->attempts)),
				htmlspecialchars($job->lastError),
				htmlspecialchars($errorSnippet)
			);
		}

		return sprintf(
			'<section class="jobqueue-preview-section">
				<h3>Failed Jobs</h3>
				<div class="jobqueue-table-wrapper">
					<table class="jobqueue-preview failed-jobs cms-colors">
						<thead>
							<tr>
								<th>Type</th>
								<th>Collection</th>
								<th>Object ID</th>
								<th>Attempts</th>
								<th>Error</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</section>',
			$rows
		);
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

	/**
	 * Group templates by folder for display in admin sidebar.
	 *
	 * @return array<string,array<array<string,string>>>
	 */
	public function templatesByFolder(): array
	{
		// Get all templates recursively
		$templates = $this->templateLister->listBuilderTemplates(null, true);

		$folders = [];

		foreach ($templates as $path) {
			// Parse path to get folder and template name
			[$folder, $templateId] = TemplatePath::parse($path);

			// Determine group name
			$groupName = 'Templates';
			if ($folder !== null) {
				// Convert folder path to group name (e.g., "pages/blog" -> "Pages / Blog")
				$parts     = explode('/', str_replace('-', ' ', $folder));
				$groupName = implode(' / ', array_map(ucwords(...), $parts));
			}

			// Create template entry
			if (!array_key_exists($groupName, $folders)) {
				$folders[$groupName] = [];
			}

			$resolved = $this->paths->resolveRead($path . TemplateRepository::FILE_EXT);

			$folders[$groupName][] = [
				'id'     => $templateId,
				'folder' => $folder ?? '',
				'path'   => $path, // Full path for linking
				'source' => $resolved['layer'] ?? '',
			];
		}

		// Sort folders alphabetically, but keep "Templates" (root) at the bottom
		uksort($folders, function ($a, $b): int {
			if ($a === 'Templates') {
				return 1;
			}
			if ($b === 'Templates') {
				return -1;
			}

			return strcmp($a, $b);
		});

		return $folders;
	}

	/**
	 * Get the builder file tree organized by category.
	 *
	 * @return array<string,list<array{id:string,path:string}>>
	 */
	public function builderFileTree(): array
	{
		$tree       = [];
		$categories = TemplateRepository::BUILDER_CATEGORIES;

		foreach ($categories as $category) {
			$templates       = $this->templateLister->listBuilderTemplates($category, true);
			$tree[$category] = [];
			foreach ($templates as $templatePath) {
				$tree[$category][] = [
					'id'   => $templatePath,
					'path' => $category . '/' . $templatePath,
				];
			}
		}

		return $tree;
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
		$tree = [];

		foreach (TemplateRepository::BUILDER_CATEGORIES as $category) {
			$templates       = $this->templateLister->listBuilderTemplates($category, true);
			$tree[$category] = NestedFileTree::build(array_values($templates), $category);
		}

		return $tree;
	}

	// -------------------------
	// Dashboard Data Methods
	// -------------------------

	/**
	 * Get dashboard statistics.
	 *
	 * @return array<string,int>
	 */
	public function dashboardStats(): array
	{
		$collections = $this->collectionLister->listAllCollections();
		$schemas     = $this->schemaLister->listCustomSchemas();
		$templates   = $this->templateLister->listBuilderTemplates();

		// Sum totalObjects from all collections (much faster than counting index objects)
		$totalObjects = 0;
		foreach ($collections as $collection) {
			$totalObjects += $collection->totalObjects;
		}

		// Get job queue stats
		$totalJobs = count($this->jobManager->getPendingJobs()) + count($this->jobManager->getFailedJobs());

		return [
			'collections'  => count($collections),
			'schemas'      => count($schemas),
			'templates'    => count($templates),
			'totalObjects' => $totalObjects,
			'totalJobs'    => $totalJobs,
		];
	}

	/**
	 * Get recent collections (top 10 by last updated).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardRecentCollections(): array
	{
		// Get all collections
		$collections = $this->collectionLister->listAllCollections();

		$result = [];

		foreach ($collections as $collection) {
			if (!$this->auth->canAccessCollection($collection->id)) {
				continue;
			}
			// Skip system collections that have their own admin pages
			if (in_array($collection->id, ['playground', 'mailer', 'dataviews'], true) || $collection->schema === 'auth') {
				continue;
			}
			$result[] = [
				'id'           => $collection->id,
				'name'         => $collection->name,
				'schema'       => $collection->schema,
				'objectCount'  => $collection->totalObjects,
				'lastModified' => $collection->lastUpdated !== '' ? $collection->lastUpdated : null,
				'addUrl'       => "collections/{$collection->id}/add",
				'viewUrl'      => "collections/{$collection->id}",
			];
		}

		// Sort by lastUpdated (most recent first)
		usort($result, function (array $a, array $b): int {
			// Handle null lastModified values (put them at the end)
			if ($a['lastModified'] === null && $b['lastModified'] === null) {
				return 0;
			}
			if ($a['lastModified'] === null) {
				return 1;
			}
			if ($b['lastModified'] === null) {
				return -1;
			}

			// Sort by date descending (most recent first)
			return $b['lastModified'] <=> $a['lastModified'];
		});

		// Return top 10 most recently updated
		return array_slice($result, 0, 10);
	}

	/**
	 * Get collections that have no objects (might need attention).
	 * Always checks ALL collections, not just custom ones.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardEmptyCollections(): array
	{
		$collections = $this->collectionLister->listAllCollections();
		$result      = [];

		foreach ($collections as $collection) {
			// Skip system collections that have their own admin pages
			if (in_array($collection->id, ['playground', 'mailer', 'dataviews'], true) || $collection->schema === 'auth') {
				continue;
			}
			// Only include empty collections using cached totalObjects field
			if ($collection->totalObjects === 0 && $this->auth->canAccessCollection($collection->id)) {
				$result[] = [
					'id'      => $collection->id,
					'name'    => $collection->name,
					'schema'  => $collection->schema,
					'addUrl'  => "collections/{$collection->id}/add",
					'viewUrl' => "collections/{$collection->id}",
				];
			}
		}

		// Sort by name
		usort($result, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		return $result;
	}

	/**
	 * Get system status information.
	 *
	 * @return array<string,mixed>
	 */
	public function dashboardSystemStatus(): array
	{
		$cacheStats    = $this->cacheReporter->getCacheStats();
		$services      = $cacheStats['services'] ?? [];
		$enabledCaches = array_filter(
			$services,
			fn ($cache): bool => is_array($cache) && isset($cache['available']) && $cache['available'] === true
		);

		$licenseStatus = $this->licenseStatus->getSidebarStatus();

		$updateInfo = null;
		try {
			$update = $this->updateChecker->checkForUpdate();
			if ($update->available) {
				$updateInfo = [
					'version'  => $update->version,
					'severity' => $update->severity,
				];
			}
		} catch (\Throwable) {
			// Update check is non-critical
		}

		return [
			'phpVersion'       => PHP_VERSION,
			'totalcmsVersion'  => $this->config->version ?? '3.0',
			'cacheBackends'    => array_keys($enabledCaches),
			'memoryLimit'      => ini_get('memory_limit'),
			'maxExecutionTime' => ini_get('max_execution_time'),
			'environment'      => $this->config->env,
			'license'          => $this->dashboardLicenseStatus($licenseStatus),
			'update'           => $updateInfo,
		];
	}

	/**
	 * License row for the system-status panel.
	 *
	 * getSidebarStatus() answers "is anything wrong?", and correctly says nothing
	 * when the answer is no — a sidebar warning icon should be absent on a healthy
	 * site. The panel asks a different question, "what licence is this?", and a
	 * status row that renders empty looks broken rather than reassuring. So a
	 * healthy licence gets a description here while the sidebar stays quiet.
	 *
	 * The severity is remapped for the same reason: LicenseStatusData defaults to
	 * `info`, and there is no `status-info` badge style, so a valid licence would
	 * otherwise render an unstyled chip.
	 *
	 * @return array<string,mixed>
	 */
	private function dashboardLicenseStatus(LicenseStatusData $status): array
	{
		// A non-empty tooltip means something needs attention — pass it through
		// untouched, including its severity and any trial countdown.
		if ($status->tooltip !== '') {
			return [
				'severity'      => $status->severity,
				'message'       => $status->tooltip,
				'daysRemaining' => $status->daysRemaining,
			];
		}

		$edition = $this->editionFeatures->getEdition();
		$message = $edition === Edition::UNKNOWN
			? 'Licensed'
			: 'Licensed — ' . ucfirst($edition->value);

		// Simulation changes what the site can do, so a panel reporting the
		// simulated edition without saying so would misrepresent the install.
		if ($this->editionFeatures->isSimulating()) {
			$message .= ' (simulated)';
		}

		return [
			'severity'      => 'success',
			'message'       => $message,
			'daysRemaining' => null,
		];
	}

	/**
	 * Get recent objects across all collections (last 10).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardRecentObjects(): array
	{
		$collections   = $this->collectionLister->listCustomCollections();
		$recentObjects = [];

		foreach ($collections as $collection) {
			try {
				$index = $this->indexReader->fetchIndex($collection->id);

				foreach ($index->objects as $object) {
					if (!isset($object['onUpdate']) && !isset($object['onCreate'])) {
						continue;
					}

					$timestamp = $object['onUpdate'] ?? $object['onCreate'] ?? null;
					if ($timestamp === null) {
						continue;
					}

					$recentObjects[] = [
						'id'             => $object['id'] ?? '',
						'collection'     => $collection->id,
						'collectionName' => $collection->name,
						'schema'         => $collection->schema,
						'timestamp'      => $timestamp,
						'editUrl'        => "collections/{$collection->id}/{$object['id']}",
						// Try to get a display name from common fields
						'displayName' => $object['title'] ?? $object['name'] ?? $object['id'] ?? 'Untitled',
					];
				}
			} catch (\Exception) {
				// Skip if collection has no index
				continue;
			}
		}

		// Sort by timestamp descending
		usort($recentObjects, fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

		// Return only the 10 most recent
		return array_slice($recentObjects, 0, 10);
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
		$alerts = [];

		// 1. Update available
		try {
			$update = $this->updateChecker->checkForUpdate(false);
			if ($update->available) {
				$alerts[] = [
					'level'    => 'warning',
					'message'  => "Total CMS {$update->version} is available.",
					'link'     => 'utils/update',
					'linkText' => 'View update',
				];
			}
		} catch (\Throwable) {
			// Update check is non-critical — skip silently
		}

		// 2. License / version-authorization
		$licenseData = $this->licenseStatus->getSidebarStatus();
		// Trial statuses carry a non-null daysRemaining (no other license
		// condition does). Keep the trial out of the alerts panel until it has
		// 30 days or less left — the sidebar icon still reflects it earlier.
		$isDistantTrial = $licenseData->daysRemaining !== null && $licenseData->daysRemaining > 30;
		if ($licenseData->showIcon && !$isDistantTrial) {
			$alerts[] = [
				'level'    => $this->mapLicenseSeverity($licenseData->severity),
				'message'  => $licenseData->tooltip !== '' ? $licenseData->tooltip : 'License requires attention.',
				'link'     => 'utils/license-manager',
				'linkText' => 'License Manager',
			];
		}

		// 3. Job queue stalled
		$queueHealth = $this->jobQueueHealth->status();
		if ($queueHealth->stalled) {
			$alerts[] = [
				'level'    => 'warning',
				'message'  => "Job queue appears stalled — {$queueHealth->pendingCount} job(s) waiting over {$queueHealth->thresholdMinutes} minutes.",
				'link'     => 'utils/jobqueue',
				'linkText' => 'Job Queue',
			];
		}

		// 4. Failed automations
		$latestRuns  = $this->automationRunReader->latestPerAutomation();
		$failedCount = 0;
		foreach ($latestRuns as $run) {
			if (($run['status'] ?? '') === 'failed') {
				$failedCount++;
			}
		}

		if ($failedCount > 0) {
			$noun     = $failedCount === 1 ? 'automation' : 'automations';
			$alerts[] = [
				'level'    => 'warning',
				'message'  => "{$failedCount} {$noun} failed on last run.",
				'link'     => 'automations',
				'linkText' => 'View automations',
			];
		}

		// 5. Extension boot failures (enabled extensions only)
		$failedExtensions = [];
		foreach ($this->extensionStateRepository->loadAll() as $extId => $state) {
			if ($state->enabled && $state->error !== null) {
				$failedExtensions[] = $extId;
			}
		}

		if ($failedExtensions !== []) {
			$count    = count($failedExtensions);
			$noun     = $count === 1 ? 'extension' : 'extensions';
			$alerts[] = [
				'level'    => 'warning',
				'message'  => "{$count} {$noun} failed to load: " . implode(', ', $failedExtensions) . '.',
				'link'     => 'extensions',
				'linkText' => 'View extensions',
			];
		}

		// 6. PHP upload limits too low for a single upload chunk. Image, gallery,
		// file and depot uploads are chunked at 5MB, so the whole-file size no
		// longer matters — but post_max_size and upload_max_filesize must each
		// still fit one chunk (plus overhead) or every chunk POST fails. Only
		// meaningful under a web SAPI — the CLI ini values don't reflect the web
		// server's real capacity, so skip the check when running from the CLI.
		$minChunkMb = 6; // 5MB chunk (see droplet.js) + multipart overhead
		$postMax    = PHP_SAPI === 'cli' ? 0 : $this->iniSizeToBytes((string)ini_get('post_max_size'));
		$uploadMax  = PHP_SAPI === 'cli' ? 0 : $this->iniSizeToBytes((string)ini_get('upload_max_filesize'));
		// A value of 0 means "unlimited" (post_max_size) or CLI — never warn on that.
		$limits = array_filter([$postMax, $uploadMax], static fn (int $b): bool => $b > 0);
		if ($limits !== [] && min($limits) < $minChunkMb * 1024 * 1024) {
			$alerts[] = [
				'level'    => 'warning',
				'message'  => sprintf(
					'PHP upload limits are very low (post_max_size: %s, upload_max_filesize: %s). Uploads are chunked at 5MB, so raise both to at least %dMB in php.ini.',
					ini_get('post_max_size') ?: 'unset',
					ini_get('upload_max_filesize') ?: 'unset',
					$minChunkMb,
				),
				'link'     => null,
				'linkText' => null,
			];
		}

		return $alerts;
	}

	/**
	 * Parse a PHP ini shorthand size ("8M", "2G", "512K", "8388608") to bytes.
	 * Returns 0 for empty/"0" so callers can treat it as "unlimited".
	 */
	private function iniSizeToBytes(string $value): int
	{
		$value = trim($value);
		if ($value === '') {
			return 0;
		}

		$number = (int)$value;
		$unit   = strtolower($value[strlen($value) - 1]);

		return match ($unit) {
			'g'     => $number * 1024 * 1024 * 1024,
			'm'     => $number * 1024 * 1024,
			'k'     => $number * 1024,
			default => $number,
		};
	}

	/**
	 * Map a LicenseStatusData severity string to a dashboardAlerts level value.
	 * LicenseStatusData uses 'info'|'warning'|'error' which already matches our
	 * alert level vocabulary, but we validate to be safe.
	 */
	private function mapLicenseSeverity(string $severity): string
	{
		return match ($severity) {
			'error'   => 'error',
			'warning' => 'warning',
			default   => 'info',
		};
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
		$objects = $this->automationLoader->enabled();
		if ($objects === []) {
			return [];
		}

		$latestRuns = $this->automationRunReader->latestPerAutomation();

		$result = [];
		foreach ($objects as $object) {
			$id = $object->id;

			// Trigger type via a mixed-typed helper: the Collection's generic
			// (PropertyData) conflicts with the runtime value (a raw deck array),
			// and a helper param avoids a @var tag that rector strips each clean.
			$triggerType = $this->firstTriggerType($object->properties->get('triggers'));

			// Run reader data
			$run        = $latestRuns[$id] ?? null;
			$lastResult = null;
			$lastRunAt  = null;

			if ($run !== null) {
				$rawStatus  = $run['status'] ?? null;
				$lastResult = is_string($rawStatus) && in_array($rawStatus, ['success', 'failed'], true)
					? $rawStatus
					: null;
				$rawRunAt  = $run['runAt'] ?? null;
				$lastRunAt = is_int($rawRunAt) ? $rawRunAt : (is_numeric($rawRunAt) ? (int)$rawRunAt : null);
			}

			$result[] = [
				'id'         => $id,
				'name'       => (string)$object->properties->get('name'),
				'trigger'    => $triggerType,
				'enabled'    => true,
				'lastResult' => $lastResult,
				'lastRunAt'  => $lastRunAt,
				'nextRunAt'  => null,
			];
		}

		return $result;
	}

	/**
	 * First trigger's `type` from an automation's raw `triggers` value.
	 *
	 * Typed `mixed` deliberately: the automation object's Illuminate Collection
	 * is generically typed as holding PropertyData, but the `triggers` deck is
	 * stored as a raw array at runtime, so the value is handled dynamically.
	 */
	private function firstTriggerType(mixed $triggers): string
	{
		if (!is_array($triggers) || $triggers === []) {
			return '';
		}

		$first = array_values($triggers)[0];

		return is_array($first) ? (string)($first['type'] ?? '') : '';
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

	// -------------------------
	// Builder Route Detection
	// -------------------------

	/**
	 * Check if a builder page route covers a collection's URL pattern.
	 *
	 * Returns the matching builder page data, or null if no match.
	 *
	 * @return array{id:string,title:string,route:string}|null
	 */
	public function builderRouteForCollection(string $collectionId): ?array
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof \TotalCMS\Domain\Collection\Data\CollectionData || $collection->url === '' || !$collection->prettyUrl) {
			return null;
		}

		// Convert collection URL pattern to builder route format
		$expectedRoute = $this->collectionUrlToBuilderRoute($collection->url);

		// Fetch builder pages
		$pagesCollectionId = $this->builderConfig->getPagesCollectionId();
		if (!$this->builderConfig->pagesCollectionExists()) {
			return null;
		}

		try {
			$index = $this->indexReader->fetchIndex($pagesCollectionId);
		} catch (\Exception) {
			return null;
		}

		foreach ($index->objects as $object) {
			$page = new PageData($object);
			if (!$page->isPublished() || $page->route === '') {
				continue;
			}

			if ($this->routeMatchesPattern($page->route, $expectedRoute)) {
				return ['id' => $page->id, 'title' => $page->title, 'route' => $page->route];
			}
		}

		return null;
	}

	/**
	 * Convert a collection URL template to a builder route pattern.
	 *
	 * /blog                         → /blog/{id}
	 * /blog/{{ category }}/{{ id }} → /blog/{category}/{id}
	 */
	private function collectionUrlToBuilderRoute(string $url): string
	{
		// Strip query string
		$path = strval(parse_url($url, PHP_URL_PATH));
		$path = rtrim($path, '/');

		// Replace {{ field }} or {{ field | filter }} with {field}
		$route = (string)preg_replace('/\{\{\s*(\w+)(?:\s*\|[^}]*)?\s*\}\}/', '{$1}', $path);

		// If no {param} tokens, it's a simple pretty URL — append {id}
		if (!str_contains($route, '{')) {
			// Strip .php extension if present
			if (str_ends_with($route, '.php')) {
				$route = dirname($route);
			}
			$route = rtrim($route, '/') . '/{id}';
		}

		return $route;
	}

	/**
	 * Check if a builder page route matches the expected pattern.
	 *
	 * Normalizes both routes and compares structure (same number of segments,
	 * static segments match, dynamic segments align).
	 */
	private function routeMatchesPattern(string $pageRoute, string $expectedRoute): bool
	{
		$pageRoute     = rtrim($pageRoute, '/');
		$expectedRoute = rtrim($expectedRoute, '/');

		$pageSegments     = explode('/', ltrim($pageRoute, '/'));
		$expectedSegments = explode('/', ltrim($expectedRoute, '/'));

		if (count($pageSegments) !== count($expectedSegments)) {
			return false;
		}

		foreach ($pageSegments as $i => $segment) {
			$expected          = $expectedSegments[$i];
			$segIsDynamic      = str_starts_with($segment, '{') && str_ends_with($segment, '}');
			$expectedIsDynamic = str_starts_with($expected, '{') && str_ends_with($expected, '}');

			// Both dynamic — match
			if ($segIsDynamic && $expectedIsDynamic) {
				continue;
			}

			// Both static — must be equal
			if (!$segIsDynamic && !$expectedIsDynamic && $segment === $expected) {
				continue;
			}

			// One dynamic, one static — no match
			return false;
		}

		return true;
	}

	/**
	 * Get collections that are inaccessible due to edition restrictions.
	 *
	 * @return array<\TotalCMS\Domain\Collection\Data\CollectionData>
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
