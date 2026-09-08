<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Data\JobQueueHealthData;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\LicenseStatusData;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Support\Config;

/**
 * The admin dashboard's data panels behind `cms.admin.dashboard*()`: stats,
 * recent and empty collections, recent objects, system status (with the
 * license row), the aggregated alerts, automations, and job-queue health.
 *
 * AdminTwigAdapter is the Twig-facing entry point and delegates here.
 */
readonly class DashboardRenderer
{
	public function __construct(
		private Config $config,
		private AuthTwigAdapter $auth,
		private CollectionLister $collectionLister,
		private SchemaLister $schemaLister,
		private TemplateLister $templateLister,
		private JobManager $jobManager,
		private CacheReporter $cacheReporter,
		private LicenseStatus $licenseStatus,
		private IndexReader $indexReader,
		private UpdateChecker $updateChecker,
		private JobQueueHealth $jobQueueHealth,
		private EditionFeatureService $editionFeatures,
		private AutomationLoader $automationLoader,
		private AutomationRunReader $automationRunReader,
		private ExtensionStateRepository $extensionStateRepository,
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
	 * Get dashboard statistics.
	 *
	 * @return array<string,int>
	 */
	public function dashboardStats(): array
	{
		$collections = $this->collectionLister->listAllCollections();
		$schemas     = $this->schemaLister->listCustomSchemas();
		// Recursive: builder templates live in layouts/, pages/, partials/ and
		// macros/, so a non-recursive scan only sees files sitting loose at the
		// top of builder/ — of which a conventionally organised site has none,
		// and the dashboard reported 0 templates. Every other whole-tree caller
		// already passes true here.
		$templates   = $this->templateLister->listBuilderTemplates(null, true);

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
					// The reserved schemas index their timestamps as `updated` /
					// `created` (the date properties flagged onUpdate/onCreate);
					// `onUpdate` / `onCreate` are kept for custom schemas that named
					// the properties after the flag.
					$timestamp = $object['updated'] ?? $object['onUpdate'] ?? $object['created'] ?? $object['onCreate'] ?? null;
					if (!is_string($timestamp) || $timestamp === '') {
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

		// 2b. Unregistered trial — no contact on file means no expiry reminders
		if ($this->licenseStatus->isUnregisteredTrial()) {
			$alerts[] = [
				'level'    => 'info',
				'message'  => 'Register your trial to get email reminders before it expires.',
				'link'     => 'utils/license-manager',
				'linkText' => 'Register trial',
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
}
