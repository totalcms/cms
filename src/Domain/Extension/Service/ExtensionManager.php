<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ExtensionEventPayload;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Orchestrates extension discovery, loading, and lifecycle.
 */
class ExtensionManager
{
	/** @var array<string,ExtensionManifest> */
	private array $discoveredManifests = [];

	/** @var array<string,ExtensionContext> */
	private array $contexts = [];

	/** @var array<string,ExtensionInterface> */
	private array $loadedExtensions = [];

	/** @var array<string,list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}>> */
	private array $extensionRoutes = [];

	/** @var array<string,list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}>> */
	private array $extensionAdminRoutes = [];

	private bool $registered = false;
	private bool $booted     = false;

	/**
	 * Capabilities considered "risky" — they either expose publicly accessible
	 * surface or grant access to sensitive data. Each maps to a plain-language
	 * FYI label shown on the pre-enable review screen. Single source of truth.
	 *
	 * @var array<string,string>
	 */
	private const RISKY_CAPABILITIES = [
		'routes:public' => 'Exposes public, unauthenticated endpoints.',
		'events:listen' => 'Can observe all content changes.',
		'automations'   => 'Runs server-side code automatically on a schedule or content events.',
		// 'container' is deliberately NOT here: it is always-on infrastructure
		// (ExtensionContext::ALWAYS_ON_CAPABILITIES) and extensions can only
		// register their OWN services — core/known service IDs are strict-denied
		// at apply time (see isProtectedServiceId), so there is nothing risky
		// to disclose.
		'mcp:tools'     => 'Registers actions AI agents can call (reachable externally if MCP public access is enabled).',
		'mcp:resources' => 'Exposes data that AI agents can fetch.',
	];

	public function __construct(
		private readonly ExtensionDiscovery $discovery,
		private readonly ExtensionStateRepository $stateRepository,
		private readonly ExtensionDependencySorter $sorter,
		private readonly ExtensionSettingsManager $settingsManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ManifestValidator $manifestValidator,
		private readonly ExtensionGuard $guard,
		private readonly ExtensionProfiler $profiler,
	) {
	}

	/**
	 * Discover extensions and run the register phase for all enabled extensions.
	 */
	public function discoverAndRegister(): void
	{
		if ($this->registered) {
			return;
		}

		$this->discoveredManifests = $this->discovery->discover();
		$states                    = $this->stateRepository->loadAll();

		// Auto-register state for newly discovered extensions
		foreach ($this->discoveredManifests as $id => $manifest) {
			if (!isset($states[$id])) {
				// DIAGNOSTIC: a discovered extension with no stored state is
				// registered fresh as DISABLED. For a genuinely new extension
				// this is correct; for one that was previously enabled it means
				// its entry in extensions.json was lost (state-file reset /
				// relocated on update) — which presents as "had to re-enable".
				$this->logger->warning(sprintf(
					"Extension '%s' has no stored state — registering fresh as DISABLED (version '%s'). "
					. 'If it was previously enabled, its extensions.json state was lost.',
					$id,
					$manifest->version,
				));
				$this->stateRepository->saveState($id, new ExtensionState(
					enabled: false,
					installedAt: date('c'),
					version: $manifest->version,
				));
			}
		}

		// Sort enabled extensions by dependencies
		$enabledManifests = array_filter(
			$this->discoveredManifests,
			fn (ExtensionManifest $m): bool => $this->stateRepository->isEnabled($m->id),
		);

		// DIAGNOSTIC: snapshot the enabled/disabled split at boot. Comparing
		// this line across an upgrade shows at a glance whether the enabled set
		// shrank wholesale (state loss) versus an individual extension being
		// disabled by the re-consent gate below.
		$skippedDisabled = array_values(
			array_diff(array_keys($this->discoveredManifests), array_keys($enabledManifests))
		);
		$this->logger->info(sprintf(
			'Extension load: discovered [%s]; enabled [%s]; skipped-disabled [%s].',
			implode(', ', array_keys($this->discoveredManifests)),
			implode(', ', array_keys($enabledManifests)),
			implode(', ', $skippedDisabled),
		));

		try {
			$sortedIds = $this->sorter->sort($enabledManifests);
		} catch (\RuntimeException $e) {
			$this->logger->error('Extension dependency error: ' . $e->getMessage());
			$sortedIds = array_keys($enabledManifests);
		}

		// Register phase
		foreach ($sortedIds as $id) {
			$manifest = $enabledManifests[$id] ?? null;
			if ($manifest === null) {
				continue;
			}

			// Auto-quarantined extensions stay enabled (the operator didn't disable
			// them — the SYSTEM held them back after repeated crashes), so they pass
			// the isEnabled() filter above. Skip loading them here until the operator
			// re-enables (which clears the quarantine) so a crash-looping extension
			// can't take the request down again.
			$state = $this->stateRepository->getState($id);
			if ($state instanceof ExtensionState && $state->isQuarantined()) {
				$this->logger->warning("Extension '{$id}' is quarantined, skipping load.");

				continue;
			}

			$reasons = $this->manifestValidator->getIncompatibilityReasons($manifest);
			if ($reasons !== []) {
				$this->logger->info("Extension '{$id}' is incompatible, skipping: " . implode('; ', $reasons));
				$this->stateRepository->recordError($id, implode('; ', $reasons));

				continue;
			}

			// Update re-consent: when a SIDELOADED extension's on-disk version no
			// longer matches the version it was enabled at, the operator did not
			// review this code. Statically scan the NEW code before it can register:
			// risky patterns => disable it (and don't load it); clean => let it load
			// but record the new version so any newly-registered capability lands
			// OFF (see updateStoredCapabilities). Built-ins are exempt — they version
			// with core and ship reviewed in the package.
			if (
				$state instanceof ExtensionState
				&& !$manifest->bundled
				&& $state->version !== ''
				&& $manifest->version !== $state->version
			) {
				// DIAGNOSTIC: re-consent only triggers when the extension's OWN
				// on-disk version differs from the version it was enabled at. If
				// this fires after a Total-CMS-only update where the operator
				// did not touch the extension, the manifest version is changing
				// unexpectedly — log both values so we can see exactly what moved.
				$this->logger->warning(sprintf(
					"Extension '%s' update re-consent triggered: on-disk version '%s' != enabled-at version '%s'. Scanning new code.",
					$id,
					$manifest->version,
					$state->version,
				));

				$extPath  = $this->discovery->getExtensionPath($id);
				$findings = $extPath !== null ? (new DangerousCodeScanner())->scan($extPath) : [];

				if ($extPath === null) {
					// No path to scan — treat as clean (nothing to load anyway;
					// registerExtension will report the missing directory) but log
					// so a vanished extension dir doesn't silently skip the gate.
					$this->logger->warning("Extension '{$id}' updated but its directory could not be resolved; skipping update scan.");
				}

				if ($findings !== []) {
					// Risky update — disable, record why, bump the stored version so
					// the gate doesn't re-trigger every request, and do NOT load it.
					// The new (risky) code never registers or runs.
					$state->enabled        = false;
					$state->updateDisabled = [
						'reason'    => 'A new version of this extension added risky code patterns.',
						'findings'  => count($findings),
						'updatedAt' => gmdate('c'),
					];
					$state->version = $manifest->version;
					$this->stateRepository->saveState($id, $state);
					$this->logger->warning("Extension '{$id}' disabled: an update added risky code (" . count($findings) . ' findings).');

					continue;
				}

				// Clean update — record the new version and let it load normally.
				// Newly-registered capabilities land OFF via updateStoredCapabilities.
				$this->logger->info(sprintf(
					"Extension '%s' update re-consent: new version '%s' scanned clean; kept enabled (any new capabilities default off).",
					$id,
					$manifest->version,
				));
				$state->version = $manifest->version;
				$this->stateRepository->saveState($id, $state);
			}

			$this->registerExtension($id, $manifest);
		}

		$this->registered = true;
	}

	/**
	 * Run the boot phase for all registered extensions.
	 * Call this AFTER middleware and routes are registered.
	 */
	public function bootAll(): void
	{
		if ($this->booted) {
			return;
		}

		// Collect extension routes into lookup tables (dispatched by static route handlers)
		foreach ($this->contexts as $id => $context) {
			$state = $this->stateRepository->getState($id);

			$extRoutes = [];

			// Authenticated API routes
			if (!$state instanceof ExtensionState || $state->isPermitted('routes:api')) {
				foreach ($context->getRegisteredRoutes() as $registrar) {
					$collector = new RouteCollector(isPublic: false);
					$registrar($collector);
					$extRoutes = array_merge($extRoutes, $collector->getRoutes());
				}
			}

			// Public routes
			if (!$state instanceof ExtensionState || $state->isPermitted('routes:public')) {
				foreach ($context->getRegisteredPublicRoutes() as $registrar) {
					$collector = new RouteCollector(isPublic: true);
					$registrar($collector);
					$extRoutes = array_merge($extRoutes, $collector->getRoutes());
				}
			}

			if ($extRoutes !== []) {
				$this->extensionRoutes[$id] = $extRoutes;
			}

			// Admin routes
			if (!$state instanceof ExtensionState || $state->isPermitted('routes:admin')) {
				$adminRoutes = [];
				foreach ($context->getRegisteredAdminRoutes() as $registrar) {
					$collector = new RouteCollector(isPublic: false);
					$registrar($collector);
					$adminRoutes = array_merge($adminRoutes, $collector->getRoutes());
				}

				if ($adminRoutes !== []) {
					$this->extensionAdminRoutes[$id] = $adminRoutes;
				}
			}
		}

		// Boot phase
		foreach ($this->loadedExtensions as $id => $extension) {
			$context = $this->contexts[$id] ?? null;
			if ($context === null) {
				continue;
			}

			$start = hrtime(true);
			try {
				$extension->boot($context);
				$this->stateRepository->clearError($id);
			} catch (\Throwable $e) {
				$this->logger->error("Extension '{$id}' failed in boot(): {$e->getMessage()}", [
					'exception' => $e,
				]);
				$this->stateRepository->recordError($id, 'boot() failed: ' . $e->getMessage());

				// Remove from loaded extensions so its registrations aren't used
				unset($this->loadedExtensions[$id], $this->contexts[$id]);
			}
			$this->profiler->record($id, (int)((hrtime(true) - $start) / 1000));
		}

		// Register extension schema directories (Pro+ only)
		if ($this->container->has(SchemaRepository::class)) {
			$this->registerExtensionSchemas();
		}

		// Register extension field types in the form builder and schema property editor
		$extFieldTypes = $this->getAllFieldTypes();
		if ($extFieldTypes !== []) {
			\TotalCMS\Domain\Admin\TotalForm::registerExtensionFieldTypes($extFieldTypes);
			\TotalCMS\Domain\Admin\TotalForm::registerExtensionFieldDefaultTypes($this->getAllFieldDefaultTypes());
		}

		// Wire event listeners from extensions into the EventDispatcher
		if ($this->container->has(\TotalCMS\Domain\Event\Service\EventDispatcher::class)) {
			$eventListeners = $this->getAllEventListeners();
			if ($eventListeners !== []) {
				/** @var \TotalCMS\Domain\Event\Service\EventDispatcher $dispatcher */
				$dispatcher = $this->container->get(\TotalCMS\Domain\Event\Service\EventDispatcher::class);
				$dispatcher->registerAll($eventListeners);
			}
		}

		// Wire extension-contributed automations into the shared registry so they
		// join the schedule/event dispatch (read-only — handler is an in-memory
		// closure). Permission-gated inside getAllAutomations().
		if ($this->container->has(\TotalCMS\Domain\Automation\Service\AutomationRegistry::class)) {
			$extensionAutomations = $this->getAllAutomations();
			if ($extensionAutomations !== []) {
				/** @var \TotalCMS\Domain\Automation\Service\AutomationRegistry $automationRegistry */
				$automationRegistry = $this->container->get(\TotalCMS\Domain\Automation\Service\AutomationRegistry::class);
				foreach ($extensionAutomations as $key => $definition) {
					$automationRegistry->register($key, $definition);
				}
			}
		}

		// Wire page-middleware registrations from extensions into the registry.
		if ($this->container->has(\TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry::class)) {
			/** @var \TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry $pageMiddlewareRegistry */
			$pageMiddlewareRegistry = $this->container->get(\TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry::class);
			foreach ($this->contexts as $id => $context) {
				if (!$this->isCapabilityPermitted($id, 'page-middleware')) {
					continue;
				}
				foreach ($context->getRegisteredPageMiddleware() as $name => $serviceId) {
					try {
						$pageMiddlewareRegistry->register($name, $serviceId);
					} catch (\InvalidArgumentException $e) {
						$this->logger->warning("Extension '{$id}' page-middleware registration failed: " . $e->getMessage());
					}
				}
			}
		}

		// Wire form-action registrations from extensions into the registry.
		if ($this->container->has(FormActionRegistry::class)) {
			/** @var FormActionRegistry $formActionRegistry */
			$formActionRegistry = $this->container->get(FormActionRegistry::class);
			foreach ($this->contexts as $id => $context) {
				if (!$this->isCapabilityPermitted($id, 'form-actions')) {
					continue;
				}
				foreach ($context->getRegisteredFormActions() as $formAction) {
					$formActionRegistry->register($formAction);
				}
			}
		}

		// Wire MCP tools and resources from extensions into their respective
		// registries (strict-deny on collisions, both core-vs-extension and
		// cross-extension). Runs before Twig wiring so registries are ready
		// by the time the first /mcp request lands — boot is the latest safe
		// moment since extensions populate their contexts during register().
		if ($this->container->has(\TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry::class)) {
			/** @var \TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry $toolRegistry */
			$toolRegistry  = $this->container->get(\TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry::class);
			$mcpRegistrar  = new McpExtensionRegistrar($this->logger);
			$mcpRegistrar->register($toolRegistry, $this->getAllMcpTools());

			// Only request the ResourceRegistry singleton when extensions have
			// resources to add. Resolving the registry eagerly would trigger
			// its CollectionResourceRegistrar pass over every collection on
			// disk, which (a) is wasted work when no extension contributes
			// resources, and (b) snapshots the collection list at boot time —
			// any collection created later in the same process (e.g. in a
			// test's beforeEach) wouldn't appear in the registry.
			$extensionResources = $this->getAllMcpResources();
			$extensionTemplates = $this->getAllMcpResourceTemplates();
			if (
				($extensionResources !== [] || $extensionTemplates !== [])
				&& $this->container->has(\TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry::class)
			) {
				/** @var \TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry $resourceRegistry */
				$resourceRegistry = $this->container->get(\TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry::class);
				$mcpRegistrar->registerResources($resourceRegistry, $extensionResources);
				$mcpRegistrar->registerResourceTemplates($resourceRegistry, $extensionTemplates);
			}
		}

		// Wire extension search providers into the SearchProviderRegistry.
		// Strict-deny on collisions (matches MCP tool registrar policy). The
		// registry's register() method already throws LogicException on
		// duplicate ids — wrap each call so one bad extension can't break the
		// rest of the drain.
		if ($this->container->has(\TotalCMS\Domain\Search\Service\SearchProviderRegistry::class)) {
			/** @var \TotalCMS\Domain\Search\Service\SearchProviderRegistry $searchRegistry */
			$searchRegistry = $this->container->get(\TotalCMS\Domain\Search\Service\SearchProviderRegistry::class);
			foreach ($this->getAllMcpSearchProviders() as $extensionId => $providers) {
				foreach ($providers as $provider) {
					try {
						$searchRegistry->register($provider);
					} catch (\LogicException $e) {
						$this->logger->warning('Extension search provider registration collision; skipped', [
							'extension'   => $extensionId,
							'provider_id' => $provider->id(),
							'message'     => $e->getMessage(),
						]);
					}
				}
			}
		}

		// Wire Twig items from extensions into the TwigEngine (with collision protection)
		if ($this->container->has(\TotalCMS\Domain\Twig\Service\TwigEngine::class)) {
			/** @var \TotalCMS\Domain\Twig\Service\TwigEngine $twigEngine */
			$twigEngine = $this->container->get(\TotalCMS\Domain\Twig\Service\TwigEngine::class);

			$twigRegistrar = new TwigExtensionRegistrar($this->logger);
			$twigRegistrar->filterAndRegister(
				$twigEngine,
				$this->container->get(\TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension::class),
				$this->getAllTwigFunctions(),
				$this->getAllTwigFilters(),
				$this->getAllTwigGlobals(),
			);

			// Register extension template directories as Twig namespaces
			foreach ($this->contexts as $id => $context) {
				$templatesDir = $context->extensionPath() . '/templates';
				if (is_dir($templatesDir)) {
					$manifest  = $this->discoveredManifests[$id] ?? null;
					$namespace = $manifest !== null
						? $manifest->vendor() . '-' . $manifest->shortName()
						: str_replace('/', '-', $id);
					$twigEngine->addExtensionTemplatePath($templatesDir, $namespace);
				}
			}

			// Pass extension nav items and widgets to templates as globals
			$navItems = $this->getAllAdminNavItems();
			$widgets  = $this->getAllDashboardWidgets();

			$globals = [];
			if ($navItems !== []) {
				$globals['extensionNavItems'] = $navItems;
			}
			if ($widgets !== []) {
				$globals['extensionDashWidgets'] = $widgets;
			}

			// Wire admin + frontend assets through the CMS adapter for the
			// new cms.adminAssetsHead/Body() and cms.assetsHead/Body() helpers.
			if ($this->container->has(\TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class)) {
				/** @var \TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter $cmsAdapter */
				$cmsAdapter = $this->container->get(\TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);

				// Core T3 assets first so they render before extension assets.
				(new \TotalCMS\Domain\Twig\Service\CoreAdminAssetRegistrar())->register($cmsAdapter);
				(new \TotalCMS\Domain\Twig\Service\CoreFrontendAssetRegistrar())->register($cmsAdapter);

				$adminAssets = $this->getAllAdminAssets();
				if ($adminAssets !== []) {
					$cmsAdapter->addAdminAssets($adminAssets);
				}

				$frontendAssets = $this->getAllFrontendAssets();
				if ($frontendAssets !== []) {
					$cmsAdapter->addFrontendAssets($frontendAssets);
				}
			}

			if ($globals !== []) {
				$twigEngine->registerExtensionItems([], [], $globals);
			}
		}

		$this->booted = true;
	}

	// -------------------------------------------------------------------------
	// State management
	// -------------------------------------------------------------------------

	public function enable(string $extensionId): void
	{
		$manifest = $this->discoveredManifests[$extensionId] ?? null;

		if ($manifest instanceof ExtensionManifest) {
			$reasons = $this->manifestValidator->getIncompatibilityReasons($manifest);
			if ($reasons !== []) {
				throw new \RuntimeException(
					"Extension '{$extensionId}' cannot be enabled: " . implode('; ', $reasons),
				);
			}
		}

		// Detect capabilities by doing a trial register
		$capabilities = $this->detectCapabilities($extensionId);

		$state = $this->stateRepository->getState($extensionId);
		if (!$state instanceof ExtensionState) {
			$state = new ExtensionState(
				enabled: true,
				installedAt: date('c'),
				version: $manifest->version ?? '0.0.0',
				permissions: $capabilities,
			);
		} else {
			$state->enabled = true;
			$state->error   = null;

			// Re-enabling clears any auto-quarantine and resets the rolling failure
			// counter so the extension starts from a clean slate. Without the reset a
			// counter still sitting at (or near) the threshold would let one fresh
			// crash immediately re-quarantine the extension. Harmless on a normal
			// enable where there's no quarantine — it just zeroes an already-empty
			// counter.
			if ($state->isQuarantined()) {
				$state->clearQuarantine();
				$this->guard->resetFailures($extensionId);
			}

			// Re-enabling after an update-disabled gate: clear the marker so the
			// banner disappears and the extension boots normally going forward.
			if ($state->isUpdateDisabled()) {
				$state->clearUpdateDisabled();
			}

			// On first enable (no permissions set yet), turn on all detected capabilities.
			// On re-enable, preserve the user's existing permission choices but add
			// any new capabilities the extension may have gained.
			if ($state->permissions === []) {
				$state->permissions = $capabilities;
			} else {
				// Add new capabilities as ON, keep existing choices
				foreach (array_keys($capabilities) as $cap) {
					if (!isset($state->permissions[$cap])) {
						$state->permissions[$cap] = true;
					}
				}
				// Remove capabilities the extension no longer uses
				$state->permissions = array_intersect_key($state->permissions, $capabilities);
			}
		}

		$this->stateRepository->saveState($extensionId, $state);
		$this->dispatchEvent(CoreEvent::EXTENSION_ENABLED, new ExtensionEventPayload($extensionId));
	}

	public function isEnabled(string $extensionId): bool
	{
		return $this->stateRepository->isEnabled($extensionId);
	}

	/**
	 * Pre-enable review data: the developer's review note, what the extension
	 * registers (capabilities), which of those are risky (with plain-language
	 * labels), and risky source patterns found by a one-time scan. Informational —
	 * not enforcement. `hasFlags` tells the review page whether there's anything
	 * worth showing at all.
	 *
	 * @return array{
	 *   capabilities: array<string,bool>,
	 *   findings: list<array{pattern:string,file:string,line:int,snippet:string}>,
	 *   reviewNote: string,
	 *   risky: array<string,string>,
	 *   hasFlags: bool
	 * }
	 */
	public function getEnableReview(string $extensionId): array
	{
		$manifest = $this->discoveredManifests[$extensionId] ?? null;
		if ($manifest === null) {
			return ['capabilities' => [], 'findings' => [], 'reviewNote' => '', 'risky' => [], 'hasFlags' => false];
		}

		try {
			$capabilities = $this->detectCapabilities($extensionId);
		} catch (\Throwable $e) {
			$this->logger->warning("getEnableReview capability detection failed for '{$extensionId}': " . $e->getMessage());
			$capabilities = [];
		}

		// Bundled extensions are exempt from the source scan — they version
		// with core and ship reviewed in the package (same rationale as the
		// update re-consent gate). Capability FYIs still show below; only the
		// pattern findings are skipped.
		$extPath  = $this->discovery->getExtensionPath($extensionId);
		$findings = ($extPath !== null && !$manifest->bundled)
			? (new DangerousCodeScanner())->scan($extPath)
			: [];

		// Intersect detected capabilities with the risky set, preserving the
		// stable order defined by RISKY_CAPABILITIES.
		$risky = [];
		foreach (self::RISKY_CAPABILITIES as $cap => $label) {
			if (($capabilities[$cap] ?? false) === true) {
				$risky[$cap] = $label;
			}
		}

		return [
			'capabilities' => $capabilities,
			'findings'     => $findings,
			'reviewNote'   => $manifest->reviewNote,
			'risky'        => $risky,
			'hasFlags'     => $risky !== [] || $findings !== [],
		];
	}

	public function getExtensionPath(string $extensionId): ?string
	{
		if (!$this->isEnabled($extensionId)) {
			return null;
		}

		return $this->discovery->getExtensionPath($extensionId);
	}

	public function disable(string $extensionId): void
	{
		$state = $this->stateRepository->getState($extensionId);
		if ($state instanceof ExtensionState) {
			$state->enabled = false;
			$state->error   = null;
			$this->stateRepository->saveState($extensionId, $state);
			$this->dispatchEvent(CoreEvent::EXTENSION_DISABLED, new ExtensionEventPayload($extensionId));
		}
	}

	/**
	 * Update permissions for an extension.
	 *
	 * @param array<string,bool> $permissions
	 */
	public function savePermissions(string $extensionId, array $permissions): void
	{
		$state = $this->stateRepository->getState($extensionId);
		if (!$state instanceof ExtensionState) {
			return;
		}

		$state->permissions = $permissions;
		$this->stateRepository->saveState($extensionId, $state);
	}

	/**
	 * Get the permissions for an extension.
	 *
	 * @return array<string,bool>
	 */
	public function getPermissions(string $extensionId): array
	{
		$state = $this->stateRepository->getState($extensionId);

		return $state instanceof ExtensionState ? $state->permissions : [];
	}

	/**
	 * Get detected capabilities for an extension that is currently loaded.
	 *
	 * @return array<string,bool>
	 */
	public function getCapabilities(string $extensionId): array
	{
		$context = $this->contexts[$extensionId] ?? null;
		if ($context !== null) {
			return $context->getCapabilities();
		}

		// Fall back to stored permissions (which reflect what was detected at enable time)
		return $this->getPermissions($extensionId);
	}

	/**
	 * Save form data from the extension settings page.
	 *
	 * Separates permission fields (perm_*) from custom settings,
	 * saves permissions to state and custom settings to disk.
	 *
	 * @param array<string,mixed> $formData Raw form POST body (without framework fields)
	 */
	public function saveFormData(string $extensionId, array $formData): void
	{
		$permissions    = $this->getPermissions($extensionId);
		$newPermissions = [];
		$customSettings = [];

		foreach ($formData as $key => $value) {
			if (str_starts_with((string)$key, 'perm_')) {
				$capability = str_replace('_', ':', substr((string)$key, 5));
				if (isset($permissions[$capability])) {
					$newPermissions[$capability] = in_array($value, ['1', 'on', 'true', true], true);
				}
			} else {
				$customSettings[$key] = $value;
			}
		}

		// Save permissions — unchecked toggles won't be submitted, so default to
		// false. Always-on capabilities (infrastructure, e.g. container defs) have
		// no toggle and stay true regardless of what the form submitted.
		if ($newPermissions !== [] || $permissions !== []) {
			$mergedPermissions = [];
			foreach (array_keys($permissions) as $cap) {
				$mergedPermissions[$cap] = in_array($cap, ExtensionContext::ALWAYS_ON_CAPABILITIES, true)
					? true
					: ($newPermissions[$cap] ?? false);
			}
			$this->savePermissions($extensionId, $mergedPermissions);
		}

		$this->settingsManager->saveSettings($extensionId, $customSettings);
	}

	/**
	 * Build a list of all discovered extensions with their current state.
	 *
	 * Used by the admin UI to display the extensions management page.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listExtensions(): array
	{
		$manifests        = $this->discovery->discover();
		$states           = $this->stateRepository->loadAll();
		$capabilityLabels = ExtensionContext::capabilityLabels();

		$extensions = [];
		foreach ($manifests as $id => $manifest) {
			if ($manifest->hidden) {
				continue;
			}
			$extensions[] = $this->buildExtensionInfo($id, $manifest, $states[$id] ?? null, $capabilityLabels);
		}

		// Sort: enabled first, then alphabetical by name
		usort($extensions, function (array $a, array $b): int {
			if ($a['enabled'] !== $b['enabled']) {
				return $b['enabled'] <=> $a['enabled'];
			}

			return strcasecmp((string)$a['name'], (string)$b['name']);
		});

		return $extensions;
	}

	/**
	 * Look up the same array shape `listExtensions()` returns for a single extension.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getExtension(string $extensionId): ?array
	{
		$manifest = $this->discovery->discover()[$extensionId] ?? null;
		if ($manifest === null) {
			return null;
		}

		$state = $this->stateRepository->loadAll()[$extensionId] ?? null;

		return $this->buildExtensionInfo($extensionId, $manifest, $state, ExtensionContext::capabilityLabels());
	}

	/**
	 * @param array<string,string> $capabilityLabels
	 *
	 * @return array<string,mixed>
	 */
	private function buildExtensionInfo(
		string $id,
		ExtensionManifest $manifest,
		?ExtensionState $state,
		array $capabilityLabels,
	): array {
		$enabled     = $state instanceof ExtensionState && $state->enabled;
		$permissions = $state instanceof ExtensionState ? $state->permissions : [];

		$capabilities = [];
		foreach ($permissions as $cap => $capEnabled) {
			if ($capEnabled) {
				$capabilities[] = $capabilityLabels[$cap] ?? $cap;
			}
		}

		return [
			'id'                   => $id,
			'name'                 => $manifest->name,
			'description'          => $manifest->description,
			'version'              => $manifest->version,
			'author'               => $manifest->author,
			'license'              => $manifest->license,
			'capabilities'         => $capabilities,
			'enabled'              => $enabled,
			'error'                => $state?->error,
			'quarantined'          => $state instanceof ExtensionState && $state->isQuarantined(),
			'quarantineReason'     => $state?->quarantine['lastError'] ?? null,
			'updateDisabled'       => $state instanceof ExtensionState && $state->isUpdateDisabled(),
			'updateDisabledReason' => $state?->updateDisabled['reason'] ?? null,
			'updateFindings'       => $state?->updateDisabled['findings'] ?? null,
			'health'               => $this->profiler->metricsFor($id),
			'errorCount'           => $this->guard->failureCountFor($id),
			'incompatibility'      => $this->manifestValidator->getIncompatibilityReasons($manifest),
			'links'                => $manifest->links,
			'hasSettings'          => $enabled && ($permissions !== [] || $manifest->settingsSchema !== null),
			'icon'                 => $this->resolveIcon($id, $manifest),
			'hidden'               => $manifest->hidden,
		];
	}

	// -------------------------------------------------------------------------
	// Accessors for collected registrations (filtered by permissions)
	// -------------------------------------------------------------------------

	/** @return list<TwigFunction> */
	public function getAllTwigFunctions(): array
	{
		$functions = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'twig:functions')) {
				continue;
			}
			foreach ($context->getRegisteredTwigFunctions() as $fn) {
				$functions[] = $this->guardTwigFunction($id, $fn);
			}
		}

		return $functions;
	}

	/**
	 * Per-extension map of MCP tools, in the shape McpExtensionRegistrar expects:
	 * `{extensionId => list<McpToolDefinition>}`. The id-keyed map (instead of a
	 * flat list) lets the registrar attribute collisions to a specific extension
	 * in the warning log.
	 *
	 * @return array<string,list<\TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition>>
	 */
	public function getAllMcpTools(): array
	{
		$byExtension = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'mcp:tools')) {
				continue;
			}
			$tools = $context->getRegisteredMcpTools();
			if ($tools !== []) {
				$byExtension[$id] = $tools;
			}
		}

		return $byExtension;
	}

	/**
	 * Per-extension map of MCP resources. Same id-keyed shape as getAllMcpTools()
	 * so McpExtensionRegistrar can attribute collisions to a specific extension.
	 * Gated by the `mcp:resources` capability permission.
	 *
	 * @return array<string,list<array{uri: string, name: string, description: string, handler: \Closure, access: string, mimeType: string}>>
	 */
	public function getAllMcpResources(): array
	{
		$byExtension = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'mcp:resources')) {
				continue;
			}
			$resources = $context->getRegisteredMcpResources();
			if ($resources !== []) {
				$byExtension[$id] = $resources;
			}
		}

		return $byExtension;
	}

	/**
	 * Per-extension map of MCP resource templates. Same shape + gating as
	 * getAllMcpResources(); templates and concrete resources share the
	 * `mcp:resources` capability flag.
	 *
	 * @return array<string,list<array{uriTemplate: string, name: string, description: string, handler: \Closure, access: string, mimeType: string}>>
	 */
	public function getAllMcpResourceTemplates(): array
	{
		$byExtension = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'mcp:resources')) {
				continue;
			}
			$templates = $context->getRegisteredMcpResourceTemplates();
			if ($templates !== []) {
				$byExtension[$id] = $templates;
			}
		}

		return $byExtension;
	}

	/**
	 * Per-extension list of registered search providers. Drained during
	 * boot() into the SearchProviderRegistry; collisions with the built-in
	 * 'text' provider OR another extension's provider are logged + skipped.
	 * Gated by the `mcp:search` capability permission.
	 *
	 * @return array<string,list<\TotalCMS\Domain\Search\Service\SearchProvider>>
	 */
	public function getAllMcpSearchProviders(): array
	{
		$byExtension = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'mcp:search')) {
				continue;
			}
			$providers = $context->getRegisteredSearchProviders();
			if ($providers !== []) {
				$byExtension[$id] = $providers;
			}
		}

		return $byExtension;
	}

	/**
	 * Per-extension map of code-defined MCP prompts. The id-keyed map lets
	 * McpServerFactory attribute collisions to a specific extension in the warning
	 * log. Gated by the `mcp:prompts` capability permission.
	 *
	 * @return array<string,list<array{prompt: \Mcp\Schema\Prompt, handler: callable, access: string}>>
	 */
	public function getAllMcpPrompts(): array
	{
		$byExtension = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'mcp:prompts')) {
				continue;
			}
			$prompts = $context->getRegisteredMcpPrompts();
			if ($prompts !== []) {
				$byExtension[$id] = $prompts;
			}
		}

		return $byExtension;
	}

	/** @return list<TwigFilter> */
	public function getAllTwigFilters(): array
	{
		$filters = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'twig:filters')) {
				continue;
			}
			foreach ($context->getRegisteredTwigFilters() as $filter) {
				$filters[] = $this->guardTwigFilter($id, $filter);
			}
		}

		return $filters;
	}

	/**
	 * Rebuild an extension TwigFunction with its callable wrapped in the guard.
	 *
	 * A broken extension Twig function is the single biggest white-screen source:
	 * Twig functions run on the live render path, and a throw there bubbles into a
	 * 500. Wrapping the callable means a broken call renders an empty string and
	 * is crash-counted (feeding quarantine) instead of taking the page down. The
	 * original options are preserved so flags (is_safe, needs_environment,
	 * needs_context) survive — Twig prepends env/context args, which the wrapper
	 * forwards transparently via ...$args.
	 *
	 * KNOWN LIMITATION: the wrapper replaces the original callable's signature
	 * with a variadic `...$args`. Twig forwards positional args (including
	 * needs_environment/needs_context injections) faithfully through the
	 * variadic. However, an extension function invoked with named arguments in a
	 * template (e.g. `{{ ext_fn(label="x") }}`) will fail to compile because
	 * Twig reflects the wrapper and cannot match the named parameter against the
	 * variadic. Positional calls — the common case — are unaffected.
	 */
	private function guardTwigFunction(string $id, TwigFunction $fn): TwigFunction
	{
		$callable = $this->normalizeTwigCallable($fn->getCallable());
		$name     = $fn->getName();

		$guarded = fn (mixed ...$args): mixed => $this->guard->run(
			$id,
			"twig:fn:{$name}",
			fn (): mixed => $callable === null ? '' : $callable(...$args),
			fallback: '',
		);

		return new TwigFunction($name, $guarded, $this->twigCallableOptions($fn));
	}

	/**
	 * Rebuild an extension TwigFilter with its callable wrapped in the guard.
	 * Same containment rationale as guardTwigFunction() — including the
	 * KNOWN LIMITATION that named-argument invocations will fail to compile
	 * (Twig cannot match named params against the variadic wrapper); positional
	 * calls are unaffected.
	 */
	private function guardTwigFilter(string $id, TwigFilter $filter): TwigFilter
	{
		$callable = $this->normalizeTwigCallable($filter->getCallable());
		$name     = $filter->getName();

		$guarded = fn (mixed ...$args): mixed => $this->guard->run(
			$id,
			"twig:filter:{$name}",
			fn (): mixed => $callable === null ? '' : $callable(...$args),
			fallback: '',
		);

		return new TwigFilter($name, $guarded, $this->twigCallableOptions($filter));
	}

	/**
	 * Normalize a Twig callable (which may be a closure, a [class, method] pair,
	 * or null) into a uniform ?callable the guard wrapper can invoke with
	 * arbitrary args. Twig's getCallable() types the array form as
	 * `array{class-string, string}` rather than `callable`; re-typing the return
	 * as `?callable` lets the wrapper call it without PHPStan flagging the union.
	 *
	 * @param callable|array{class-string, string}|null $raw
	 */
	private function normalizeTwigCallable(callable|array|null $raw): ?callable
	{
		if ($raw === null || !is_callable($raw)) {
			return null;
		}

		return $raw;
	}

	/**
	 * Read a Twig callable's full options array.
	 *
	 * `Twig\AbstractTwigCallable` in the installed Twig (3.27) stores the merged
	 * options on a protected $options property (is_safe, needs_environment,
	 * needs_context, node_class, deprecation_info, …) but exposes no public
	 * accessor for the raw array. Reading it via reflection and passing it
	 * verbatim to the rebuilt callable preserves every flag — the alternative of
	 * reconstructing options from individual needs*() accessors would silently
	 * drop is_safe and break {{ ... }} escaping on safe HTML.
	 *
	 * @return array<string,mixed>
	 */
	private function twigCallableOptions(TwigFunction|TwigFilter $callable): array
	{
		$prop = new \ReflectionProperty(\Twig\AbstractTwigCallable::class, 'options');
		/** @var array<string,mixed> $options */
		$options = $prop->getValue($callable);

		return $options;
	}

	/** @return array<string,mixed> */
	public function getAllTwigGlobals(): array
	{
		$globals = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'twig:functions')) {
				continue;
			}
			$globals = array_merge($globals, $context->getRegisteredTwigGlobals());
		}

		return $globals;
	}

	/** @return list<Command> */
	public function getAllCommands(): array
	{
		$commands = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'cli:commands')) {
				continue;
			}
			$commands = array_merge($commands, $context->getRegisteredCommands());
		}

		return $commands;
	}

	/** @return list<AdminNavItem> */
	public function getAllAdminNavItems(): array
	{
		$items = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'admin:nav')) {
				continue;
			}
			foreach ($context->getRegisteredAdminNavItems() as $item) {
				// Stamp the owning extension so templates can apply the
				// access-group extension grant — never author-supplied.
				$items[] = $item->withExtensionId($id);
			}
		}

		usort($items, fn (AdminNavItem $a, AdminNavItem $b): int => $a->priority <=> $b->priority);

		return $items;
	}

	/** @return list<DashboardWidget> */
	public function getAllDashboardWidgets(): array
	{
		$widgets = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'admin:widgets')) {
				continue;
			}
			foreach ($context->getRegisteredDashboardWidgets() as $widget) {
				// Stamp the owning extension so templates can apply the
				// access-group extension grant — never author-supplied.
				$widgets[] = $widget->withExtensionId($id);
			}
		}

		usort($widgets, fn (DashboardWidget $a, DashboardWidget $b): int => $a->priority <=> $b->priority);

		return $widgets;
	}

	/**
	 * Enabled extensions that registered any admin surface (nav items,
	 * dashboard widgets, or admin routes), as id => display name. Drives the
	 * Extension Access list in the access-group form — extensions with no
	 * admin surface have nothing to grant, so they don't clutter the list.
	 *
	 * @return array<string,string>
	 */
	public function listExtensionsWithAdminSurface(): array
	{
		$result = [];
		foreach ($this->contexts as $id => $context) {
			$hasSurface = $context->getRegisteredAdminNavItems() !== []
				|| $context->getRegisteredDashboardWidgets() !== []
				|| $context->getRegisteredAdminRoutes() !== [];

			if (!$hasSurface) {
				continue;
			}

			$manifest    = $this->discoveredManifests[$id] ?? null;
			$result[$id] = $manifest instanceof ExtensionManifest ? $manifest->name : $id;
		}

		ksort($result);

		return $result;
	}

	/** @return array<string,class-string> */
	public function getAllFieldTypes(): array
	{
		$types = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'fields')) {
				continue;
			}
			$types = array_merge($types, $context->getRegisteredFieldTypes());
		}

		return $types;
	}

	/** @return array<string,string> */
	public function getAllFieldDefaultTypes(): array
	{
		$types = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'fields')) {
				continue;
			}
			$types = array_merge($types, $context->getRegisteredFieldDefaultTypes());
		}

		return $types;
	}

	/**
	 * Get all admin asset records (URL + position + flags) for extensions
	 * with the admin:assets capability enabled.
	 *
	 * @return list<FrontendAsset>
	 */
	private function getAllAdminAssets(): array
	{
		return $this->collectAssetRecords('admin:assets', fn (ExtensionContext $context): array => $context->getRegisteredAdminAssets());
	}

	/**
	 * Get all frontend asset records for extensions with the
	 * frontend:assets capability enabled.
	 *
	 * @return list<FrontendAsset>
	 */
	private function getAllFrontendAssets(): array
	{
		return $this->collectAssetRecords('frontend:assets', fn (ExtensionContext $context): array => $context->getRegisteredFrontendAssets());
	}

	/**
	 * Collect asset records from contexts, building servable URLs with
	 * mtime-based cache busting and applying position defaults.
	 *
	 * @param callable(ExtensionContext): list<array{type: string, path: string, position: ?string, module: bool, preload: bool, version: ?string}> $registrationsFor
	 *
	 * @return list<FrontendAsset>
	 */
	private function collectAssetRecords(string $capability, callable $registrationsFor): array
	{
		/** @var list<FrontendAsset> $records */
		$records = [];

		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, $capability)) {
				continue;
			}

			$manifest = $this->discoveredManifests[$id] ?? null;
			if ($manifest === null) {
				continue;
			}

			$extPath  = $manifest->vendor() . '/' . $manifest->shortName();
			$assetDir = $context->extensionPath() . '/assets';

			foreach ($registrationsFor($context) as $asset) {
				if ($asset['type'] !== 'css' && $asset['type'] !== 'js') {
					continue;
				}

				$relPath  = ltrim($asset['path'], '/');
				$url      = '/ext/' . $extPath . '/assets/' . $relPath;
				$version  = $asset['version'];
				if ($version === null) {
					$mtime   = @filemtime($assetDir . '/' . $relPath);
					$version = $mtime !== false ? (string)$mtime : null;
				}
				if ($version !== null && $version !== '') {
					$url .= '?v=' . $version;
				}

				$position = $asset['position'] ?? ($asset['type'] === 'css' ? 'head' : 'body');
				if ($position !== 'head' && $position !== 'body') {
					$position = $asset['type'] === 'css' ? 'head' : 'body';
				}

				$records[] = new FrontendAsset(
					type: $asset['type'],
					url: $url,
					position: $position,
					module: $asset['module'],
					preload: $asset['preload'],
				);
			}
		}

		return $records;
	}

	/** @return array<string,list<array{callable, int}>> */
	public function getAllEventListeners(): array
	{
		$listeners = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'events:listen')) {
				continue;
			}
			foreach ($context->getRegisteredEventListeners() as $event => $eventListeners) {
				foreach ($eventListeners as $listener) {
					[$callable, $priority] = $listener;

					// Wrap each listener so a throwing extension listener is
					// contained + attributed + crash-counted (feeding quarantine)
					// here, not just swallowed by the dispatcher's backstop catch.
					$guarded = fn (mixed ...$args): mixed => $this->guard->run(
						$id,
						"event:{$event}",
						fn (): mixed => $callable(...$args),
						fallback: null,
					);

					$listeners[$event][] = [$guarded, $priority];
				}
			}
		}

		// Sort each event's listeners by priority
		foreach ($listeners as $event => $eventListeners) {
			usort($eventListeners, fn (array $a, array $b): int => $a[1] <=> $b[1]);
			$listeners[$event] = $eventListeners;
		}

		return $listeners;
	}

	/**
	 * Automations contributed by enabled, permitted extensions, keyed
	 * `{extensionId}:{automationId}` so they share a flat namespace with
	 * file-based automations while staying attributable. Permission-gated by the
	 * auto-detected `automations` capability — disabling it hides the
	 * extension's automations without uninstalling.
	 *
	 * @return array<string,\TotalCMS\Domain\Extension\Data\AutomationDefinition>
	 */
	public function getAllAutomations(): array
	{
		$automations = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'automations')) {
				continue;
			}
			foreach ($context->getRegisteredAutomations() as $automation) {
				$automations["{$id}:{$automation->id}"] = $automation;
			}
		}

		return $automations;
	}

	/**
	 * Match a request against extension-registered routes.
	 */
	public function matchExtensionRoute(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		$match = $this->resolveRoute($this->extensionRoutes[$extensionId] ?? [], $method, $path);
		if ($match === null) {
			return null;
		}

		return new ExtensionRoute(
			handler: $match['route']['handler'],
			public: $match['route']['public'],
			params: $match['params'],
		);
	}

	/**
	 * Match a request against extension-registered admin routes.
	 */
	public function matchExtensionAdminRoute(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		$match = $this->resolveRoute($this->extensionAdminRoutes[$extensionId] ?? [], $method, $path);
		if ($match === null) {
			return null;
		}

		// Admin routes default to super-admin only; 'any' is the explicit
		// opt-out for pages meant for every logged-in dashboard user. Unknown
		// values normalize to 'admin' (fail closed).
		return new ExtensionRoute(
			handler: $match['route']['handler'],
			permission: ($match['route']['permission'] ?? null) === 'any' ? 'any' : 'admin',
			params: $match['params'],
		);
	}

	/**
	 * Resolve a request method+path against a list of registered routes,
	 * supporting Slim-style {placeholder} segments. An exact static match
	 * wins over a placeholder pattern (FastRoute-style precedence), so a
	 * literal `/embed/list` is never shadowed by `/embed/{id}` regardless of
	 * registration order.
	 *
	 * @param list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}> $routes
	 *
	 * @return array{route: array{method: string, path: string, handler: mixed, public: bool, permission: string|null}, params: array<string,string>}|null
	 */
	private function resolveRoute(array $routes, string $method, string $path): ?array
	{
		// Pass 1: exact static match.
		foreach ($routes as $route) {
			if ($route['method'] === $method && $route['path'] === $path) {
				return ['route' => $route, 'params' => []];
			}
		}

		// Pass 2: {placeholder} patterns.
		foreach ($routes as $route) {
			if ($route['method'] !== $method || !str_contains($route['path'], '{')) {
				continue;
			}

			$params = $this->matchRoutePath($route['path'], $path);
			if ($params !== null) {
				return ['route' => $route, 'params' => $params];
			}
		}

		return null;
	}

	/**
	 * Match a request path against a registered route pattern containing
	 * {placeholder} segments, mirroring Slim/FastRoute semantics: `{id}`
	 * captures a single non-slash segment, `{id:\d+}` adds a regex
	 * constraint. Literal portions are matched verbatim (regex-quoted).
	 * Returns the captured params on a match, or null when the path doesn't
	 * match the pattern.
	 *
	 * @return array<string,string>|null
	 */
	private function matchRoutePath(string $pattern, string $path): ?array
	{
		$regex  = '';
		$offset = 0;

		preg_match_all('/\{(\w+)(?::([^{}]+))?\}/', $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

		foreach ($matches as $match) {
			$placeholder = (string)$match[0][0];
			$position    = (int)$match[0][1];
			$name        = (string)$match[1][0];
			$constraint  = (isset($match[2]) && $match[2][1] !== -1) ? (string)$match[2][0] : '[^/]+';

			$regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');
			$regex .= '(?P<' . $name . '>' . $constraint . ')';
			$offset  = $position + strlen($placeholder);
		}

		$regex .= preg_quote(substr($pattern, $offset), '#');

		if (preg_match('#^' . $regex . '$#', $path, $captured) !== 1) {
			return null;
		}

		// Keep only the named captures (the {placeholder} values), discarding
		// preg's parallel numeric-indexed entries.
		$params = [];
		foreach ($captured as $key => $value) {
			if (is_string($key)) {
				$params[$key] = $value;
			}
		}

		return $params;
	}

	/**
	 * @return array<string,ExtensionManifest>
	 */
	public function getDiscoveredManifests(): array
	{
		return $this->discoveredManifests;
	}

	/**
	 * @return array<string,ExtensionInterface>
	 */
	public function getLoadedExtensions(): array
	{
		return $this->loadedExtensions;
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Check if a capability is permitted for an extension.
	 */
	private function isCapabilityPermitted(string $extensionId, string $capability): bool
	{
		$state = $this->stateRepository->getState($extensionId);

		return !$state instanceof ExtensionState || $state->isPermitted($capability);
	}

	/**
	 * Detect capabilities by doing a trial register of the extension.
	 *
	 * Used during enable() to discover what the extension actually registers
	 * before any permissions exist.
	 *
	 * @return array<string,bool> Capability key => true for each detected capability
	 */
	private function detectCapabilities(string $extensionId): array
	{
		// If already loaded (e.g. during bootstrap), use the live context
		$context = $this->contexts[$extensionId] ?? null;
		if ($context !== null) {
			return $context->getCapabilities();
		}

		$manifest = $this->discoveredManifests[$extensionId] ?? null;
		if ($manifest === null) {
			return [];
		}

		$extPath = $this->discovery->getExtensionPath($extensionId);
		if ($extPath === null) {
			return [];
		}

		// Load autoloader and entrypoint
		$autoloadFile = $extPath . '/vendor/autoload.php';
		if (is_file($autoloadFile)) {
			require_once $autoloadFile;
		}

		$entrypointFile = $extPath . '/' . $manifest->entrypoint;
		if (!is_file($entrypointFile)) {
			return [];
		}

		require_once $entrypointFile;

		$className = $this->resolveClassName($entrypointFile);
		if ($className === null || !class_exists($className) || !is_subclass_of($className, ExtensionInterface::class)) {
			return [];
		}

		try {
			$extension  = new $className();
			$trialCtx   = new ExtensionContext($manifest, $extPath, $this->container, $this->settingsManager, $this->logger);
			$extension->register($trialCtx);

			return $trialCtx->getCapabilities();
		} catch (\Throwable $e) {
			$this->logger->warning("Capability detection failed for '{$extensionId}': " . $e->getMessage());

			return [];
		}
	}

	private function dispatchEvent(string $event, ExtensionEventPayload $payload): void
	{
		try {
			if ($this->container->has(\TotalCMS\Domain\Event\Service\EventDispatcher::class)) {
				/** @var \TotalCMS\Domain\Event\Service\EventDispatcher $dispatcher */
				$dispatcher = $this->container->get(\TotalCMS\Domain\Event\Service\EventDispatcher::class);
				$dispatcher->dispatch($event, $payload);
			}
		} catch (\Throwable) {
			// Don't let event dispatch failures affect extension management
		}
	}

	private function registerExtensionSchemas(): void
	{
		// Extension schemas require Pro edition or higher
		if ($this->container->has(EditionFeatureService::class)) {
			/** @var EditionFeatureService $editionService */
			$editionService = $this->container->get(EditionFeatureService::class);
			if ($editionService->getEdition()->level() < Edition::PRO->level()) {
				return;
			}
		}

		/** @var SchemaRepository $schemaRepo */
		$schemaRepo = $this->container->get(SchemaRepository::class);

		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'schemas')) {
				continue;
			}

			$schemasDir = $context->extensionPath() . '/schemas';
			if (is_dir($schemasDir)) {
				$schemaRepo->registerExtensionSchemaDir($schemasDir);
				$this->logger->debug("Registered extension schemas from '{$id}'");
			}
		}
	}

	private function registerExtension(string $id, ExtensionManifest $manifest): void
	{
		$extPath = $this->discovery->getExtensionPath($id);
		if ($extPath === null) {
			$this->logger->error("Extension '{$id}' directory not found");

			return;
		}

		// Load extension autoloader
		$autoloadFile = $extPath . '/vendor/autoload.php';
		if (is_file($autoloadFile)) {
			require_once $autoloadFile;
		}

		// Load the entry point class
		$entrypointFile = $extPath . '/' . $manifest->entrypoint;
		if (!is_file($entrypointFile)) {
			$this->logger->error("Extension '{$id}' entrypoint not found: {$manifest->entrypoint}");
			$this->stateRepository->recordError($id, "Entrypoint not found: {$manifest->entrypoint}");

			return;
		}

		require_once $entrypointFile;

		// Determine the class name from the file
		$className = $this->resolveClassName($entrypointFile);
		if ($className === null || !class_exists($className)) {
			$this->logger->error("Extension '{$id}' class not found in {$manifest->entrypoint}");
			$this->stateRepository->recordError($id, "Extension class not found in {$manifest->entrypoint}");

			return;
		}

		if (!is_subclass_of($className, ExtensionInterface::class)) {
			$this->logger->error("Extension '{$id}' class does not implement ExtensionInterface");
			$this->stateRepository->recordError($id, 'Extension class does not implement ExtensionInterface');

			return;
		}

		try {
			$extension = new $className();
			$context   = new ExtensionContext($manifest, $extPath, $this->container, $this->settingsManager, $this->logger);

			$extension->register($context);

			// Apply registered container definitions to the running container.
			// Without this, addContainerDefinition() is a no-op — anything that
			// depends on container resolution (page middleware that takes injected
			// services, custom services consumed by Twig functions, etc.) would
			// silently fail to instantiate. These are infrastructure, not an
			// independent feature surface (see ExtensionContext::ALWAYS_ON_CAPABILITIES),
			// so they're always applied for an enabled extension rather than gated
			// behind a toggle that would only leave the extension enabled-but-broken.
			if ($this->container instanceof \DI\Container) {
				$compiled = $this->container instanceof \DI\CompiledContainer;
				// Strict-deny on core service overrides (same policy as Twig
				// functions and MCP tools). set() entries join the known list,
				// so cross-extension duplicates are denied here too.
				$knownEntries = array_flip($this->container->getKnownEntryNames());
				foreach ($context->getRegisteredContainerDefinitions() as $serviceId => $factory) {
					if ($this->isProtectedServiceId($serviceId, $knownEntries)) {
						$this->logger->warning("Extension '{$id}' attempted to override protected service '{$serviceId}'; definition skipped.", [
							'extension' => $id,
							'service'   => $serviceId,
						]);

						continue;
					}
					if ($compiled) {
						// A compiled PHP-DI container rejects lazy definitions (a bare
						// closure passed to set() is treated as a FactoryDefinition)
						// added at runtime. Resolve the factory now and store the built
						// instance as a raw value, which set() accepts on a compiled
						// container. Safe here: the compiled core container is fully
						// built before discovery, so the factory's dependencies exist.
						// The factory contract is fn(ContainerInterface) => object.
						$this->container->set($serviceId, $factory($this->container));
					} else {
						// Uncompiled container (dev/test): register the factory lazily
						// so it's resolved on first get() — not eagerly at register()
						// time. Eager resolution would force the factory's dependencies
						// (e.g. TwigEngine) to exist this instant and, on failure, skip
						// the whole extension instead of just that one service.
						$this->container->set($serviceId, $factory);
					}
				}
			}

			$this->loadedExtensions[$id] = $extension;
			$this->contexts[$id]         = $context;
			$this->stateRepository->clearError($id);

			// Update stored capabilities so they stay current with the extension code
			$this->updateStoredCapabilities($id, $context);
		} catch (\Throwable $e) {
			$this->logger->error("Extension '{$id}' failed in register(): {$e->getMessage()}", [
				'exception' => $e,
			]);
			$this->stateRepository->recordError($id, 'register() failed: ' . $e->getMessage());
		}
	}

	/**
	 * Whether a container service ID is protected from extension override.
	 *
	 * Two checks:
	 * - Core namespace: anything under TotalCMS\ except TotalCMS\Bundled\
	 *   (bundled extensions register their own services there). Catches
	 *   autowired core classes, which never appear in the known-entry list.
	 * - Known entries: everything config/container.php defines explicitly
	 *   (PSR-7 factories, Slim\App, third-party bindings) plus definitions
	 *   already set by previously-registered extensions.
	 *
	 * Extensions register services under their own vendor namespace, so
	 * legitimate usage never trips this.
	 *
	 * @param array<string,int> $knownEntries Flipped getKnownEntryNames()
	 */
	private function isProtectedServiceId(string $serviceId, array $knownEntries): bool
	{
		if (str_starts_with($serviceId, 'TotalCMS\\') && !str_starts_with($serviceId, 'TotalCMS\\Bundled\\')) {
			return true;
		}

		return isset($knownEntries[$serviceId]);
	}

	/**
	 * After a successful register(), update stored permissions to reflect
	 * the extension's current capabilities. Removed capabilities are pruned,
	 * existing choices are preserved, and any NEWLY-discovered capability
	 * (detected now but not already in the stored map) defaults to OFF.
	 *
	 * Rationale: a brand-new capability can only appear on an already-enabled
	 * extension via a code change (an update). Defaulting it OFF means "new
	 * features stay off until the operator opts in" — the safe-by-default
	 * complement to the update re-consent scan. This does NOT affect first
	 * enable: enable() seeds the full consented set into permissions, so on
	 * the first boot this method finds every cap already present and adds
	 * nothing — the operator's consented "on" set is preserved.
	 *
	 * Skips persisting if nothing changed — avoids hammering the state file
	 * on every request, which would otherwise create a race with concurrent
	 * readers in discoverAndRegister().
	 */
	private function updateStoredCapabilities(string $id, ExtensionContext $context): void
	{
		$state = $this->stateRepository->getState($id);
		if (!$state instanceof ExtensionState) {
			return;
		}

		$capabilities = $context->getCapabilities();
		$original     = $state->permissions;

		if ($state->permissions === []) {
			// First run — set all detected capabilities to ON. This path only
			// fires for an extension that has never stored permissions; enable()
			// normally seeds them first, so in practice this is the legacy
			// no-permissions case (isPermitted() allows all when the map is empty).
			$state->permissions = $capabilities;
		} else {
			// Add newly-discovered capabilities as OFF (safe-by-default for an
			// updated extension), but never override an always-on infrastructure
			// capability — those have no toggle and must stay true.
			foreach (array_keys($capabilities) as $cap) {
				if (!isset($state->permissions[$cap])) {
					$state->permissions[$cap] = in_array($cap, ExtensionContext::ALWAYS_ON_CAPABILITIES, true);
				}
			}
			// Remove capabilities the extension no longer uses
			$state->permissions = array_intersect_key($state->permissions, $capabilities);
		}

		if ($state->permissions === $original) {
			return;
		}

		// DIAGNOSTIC: the detected capability set changed, so permissions were
		// reconciled. If this fires after a Total-CMS-only update (extension
		// untouched), Total CMS changed what capabilities it detects — added
		// caps land OFF, removed caps are pruned, which presents as the
		// extension's features turning off even though it stayed enabled.
		$addedOff = array_values(array_diff(array_keys($state->permissions), array_keys($original)));
		$pruned   = array_values(array_diff(array_keys($original), array_keys($state->permissions)));
		$this->logger->info(sprintf(
			"Extension '%s' capabilities reconciled: added-off [%s]; pruned [%s].",
			$id,
			implode(', ', $addedOff),
			implode(', ', $pruned),
		));

		$this->stateRepository->saveState($id, $state);
	}

	/**
	 * Extract the fully qualified class name from a PHP file.
	 *
	 * Uses PHP's tokenizer rather than regex so we correctly skip comments
	 * (including the word "class" inside docblocks or line comments) and
	 * `Foo::class` constant expressions. The naive regex version once picked
	 * up "class string" from a comment and produced a bogus class name —
	 * extension authors shouldn't have to police their comments.
	 */
	private function resolveClassName(string $filePath): ?string
	{
		$contents = file_get_contents($filePath);
		if ($contents === false) {
			return null;
		}

		$tokens = token_get_all($contents);
		$count  = count($tokens);

		$namespace = '';
		$class     = '';

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!is_array($token)) {
				continue;
			}

			[$id] = $token;

			if ($id === T_NAMESPACE) {
				// Collect tokens until `;` or `{` — that's the namespace name.
				for ($j = $i + 1; $j < $count; $j++) {
					$next = $tokens[$j];
					if (is_string($next) && ($next === ';' || $next === '{')) {
						break;
					}
					if (is_array($next) && in_array($next[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
						$namespace .= $next[1];
					}
				}
				$namespace = trim($namespace);

				continue;
			}

			if ($id === T_CLASS) {
				// Skip `Foo::class` (T_CLASS preceded by `::`).
				$prev = $i - 1;
				while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					$prev--;
				}
				if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
					continue;
				}

				// Find the class name — next T_STRING token.
				for ($j = $i + 1; $j < $count; $j++) {
					$next = $tokens[$j];
					if (is_array($next) && $next[0] === T_STRING) {
						$class = $next[1];
						break 2;
					}
				}
			}
		}

		if ($class === '') {
			return null;
		}

		return $namespace !== '' ? $namespace . '\\' . $class : $class;
	}

	/**
	 * Resolve an extension's icon to a data URI, or null if no icon exists.
	 */
	private function resolveIcon(string $id, ExtensionManifest $manifest): ?string
	{
		if ($manifest->icon === '') {
			return null;
		}

		$extPath = $this->discovery->getExtensionPath($id);
		if ($extPath === null) {
			return null;
		}

		// Block path traversal
		if (str_contains($manifest->icon, '..')) {
			return null;
		}

		$iconPath = $extPath . '/' . $manifest->icon;
		if (!is_file($iconPath)) {
			return null;
		}

		// Limit to 64KB to prevent abuse
		$size = filesize($iconPath);
		if ($size === false || $size > 65536) {
			return null;
		}

		$contents = file_get_contents($iconPath);
		if ($contents === false) {
			return null;
		}

		$mime = match (strtolower(pathinfo($iconPath, PATHINFO_EXTENSION))) {
			'svg'          => 'image/svg+xml',
			'png'          => 'image/png',
			'jpg', 'jpeg'  => 'image/jpeg',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			default        => null,
		};

		if ($mime === null) {
			return null;
		}

		return 'data:' . $mime . ';base64,' . base64_encode($contents);
	}
}
