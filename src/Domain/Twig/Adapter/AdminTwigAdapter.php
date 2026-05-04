<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Util\NestedFileTree;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\CacheSizingAdvisor;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateLister;
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
	) {
	}

	/**
	 * Build an HTMX-powered quick action button.
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

		$url = rtrim($this->config->api . '/api', '/') . '/' . ltrim($route, '/');
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

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function processJobQueueCommand(): string
	{
		// php <install_dir>/resources/bin/tcms jobs:process
		$phpPath    = defined(PHP_BINARY) ? PHP_BINARY : 'php';
		$installDir = PathResolver::packageRoot();
		$command    = $installDir . '/resources/bin/tcms';

		// Quote path if it contains spaces
		$quotedCommand = str_contains($command, ' ') ? '"' . $command . '"' : $command;

		$envPrefix = $this->config->env === 'dev' ? 'APP_ENV=dev ' : '';

		return sprintf(
			'%s%s %s jobs:process',
			$envPrefix,
			$phpPath,
			$quotedCommand,
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
				htmlspecialchars($job->createdAt)
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
			[$folder, $templateId] = TemplateRepository::parsePath($path);

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

			$folders[$groupName][] = [
				'id'     => $templateId,
				'folder' => $folder ?? '',
				'path'   => $path, // Full path for linking
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
			'license'          => [
				'severity'      => $licenseStatus->severity,
				'message'       => $licenseStatus->tooltip,
				'daysRemaining' => $licenseStatus->daysRemaining,
			],
			'update'           => $updateInfo,
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
RewriteRule ^$start([\w-]+)$ $path?id=$1 [L,QSA]
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
