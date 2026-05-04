<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
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
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Orchestrates extension discovery, loading, and lifecycle.
 */
final class ExtensionManager
{
	/** @var array<string,ExtensionManifest> */
	private array $discoveredManifests = [];

	/** @var array<string,ExtensionContext> */
	private array $contexts = [];

	/** @var array<string,ExtensionInterface> */
	private array $loadedExtensions = [];

	/** @var array<string,list<array{method: string, path: string, handler: mixed, public: bool}>> */
	private array $extensionRoutes = [];

	/** @var array<string,list<array{method: string, path: string, handler: mixed, public: bool}>> */
	private array $extensionAdminRoutes = [];

	private bool $registered = false;
	private bool $booted     = false;

	public function __construct(
		private readonly ExtensionDiscovery $discovery,
		private readonly ExtensionStateRepository $stateRepository,
		private readonly ExtensionDependencySorter $sorter,
		private readonly ExtensionSettingsManager $settingsManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ManifestValidator $manifestValidator,
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

			$reasons = $this->manifestValidator->getIncompatibilityReasons($manifest);
			if ($reasons !== []) {
				$this->logger->info("Extension '{$id}' is incompatible, skipping: " . implode('; ', $reasons));
				$this->stateRepository->recordError($id, implode('; ', $reasons));

				continue;
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
		if ($this->container->has(\TotalCMS\Domain\Event\EventDispatcher::class)) {
			$eventListeners = $this->getAllEventListeners();
			if ($eventListeners !== []) {
				/** @var \TotalCMS\Domain\Event\EventDispatcher $dispatcher */
				$dispatcher = $this->container->get(\TotalCMS\Domain\Event\EventDispatcher::class);
				$dispatcher->registerAll($eventListeners);
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

			// Pass extension nav items, widgets, and assets to templates as globals
			$navItems = $this->getAllAdminNavItems();
			$widgets  = $this->getAllDashboardWidgets();
			$assets   = $this->getAllAdminAssets();

			$globals = [];
			if ($navItems !== []) {
				$globals['extensionNavItems'] = $navItems;
			}
			if ($widgets !== []) {
				$globals['extensionDashWidgets'] = $widgets;
			}
			if ($assets['css'] !== [] || $assets['js'] !== []) {
				$globals['extensionAssets'] = $assets;

				// Also set on the CMS adapter for {{ cms.extensionAssets() }}
				if ($this->container->has(\TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class)) {
					/** @var \TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter $cmsAdapter */
					$cmsAdapter = $this->container->get(\TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter::class);
					$cmsAdapter->setExtensionAssets($assets);
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
		$this->dispatchEvent('extension.enabled', new ExtensionEventPayload($extensionId));
	}

	public function isEnabled(string $extensionId): bool
	{
		return $this->stateRepository->isEnabled($extensionId);
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
			$this->dispatchEvent('extension.disabled', new ExtensionEventPayload($extensionId));
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

		// Save permissions — unchecked toggles won't be submitted, so default to false
		if ($newPermissions !== [] || $permissions !== []) {
			$mergedPermissions = [];
			foreach (array_keys($permissions) as $cap) {
				$mergedPermissions[$cap] = $newPermissions[$cap] ?? false;
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
			$state       = $states[$id] ?? null;
			$enabled     = $state !== null && $state->enabled;
			$permissions = $state !== null ? $state->permissions : [];

			$capabilities = [];
			foreach ($permissions as $cap => $capEnabled) {
				if ($capEnabled) {
					$capabilities[] = $capabilityLabels[$cap] ?? $cap;
				}
			}

			$extensions[] = [
				'id'              => $id,
				'name'            => $manifest->name,
				'description'     => $manifest->description,
				'version'         => $manifest->version,
				'author'          => $manifest->author,
				'license'         => $manifest->license,
				'capabilities'    => $capabilities,
				'enabled'         => $enabled,
				'error'           => $state?->error,
				'incompatibility' => $this->manifestValidator->getIncompatibilityReasons($manifest),
				'links'           => $manifest->links,
				'hasSettings'     => $enabled && ($permissions !== [] || $manifest->settingsSchema !== null),
				'icon'            => $this->resolveIcon($id, $manifest),
			];
		}

		// Sort: enabled first, then alphabetical by name
		usort($extensions, function (array $a, array $b): int {
			if ($a['enabled'] !== $b['enabled']) {
				return $b['enabled'] <=> $a['enabled'];
			}

			return strcasecmp($a['name'], $b['name']);
		});

		return $extensions;
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
			$functions = array_merge($functions, $context->getRegisteredTwigFunctions());
		}

		return $functions;
	}

	/** @return list<TwigFilter> */
	public function getAllTwigFilters(): array
	{
		$filters = [];
		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'twig:filters')) {
				continue;
			}
			$filters = array_merge($filters, $context->getRegisteredTwigFilters());
		}

		return $filters;
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
			$items = array_merge($items, $context->getRegisteredAdminNavItems());
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
			$widgets = array_merge($widgets, $context->getRegisteredDashboardWidgets());
		}

		usort($widgets, fn (DashboardWidget $a, DashboardWidget $b): int => $a->priority <=> $b->priority);

		return $widgets;
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
	 * Get all admin asset URLs resolved to servable paths.
	 *
	 * @return array{css: list<string>, js: list<string>}
	 */
	public function getAllAdminAssets(): array
	{
		$css = [];
		$js  = [];

		foreach ($this->contexts as $id => $context) {
			if (!$this->isCapabilityPermitted($id, 'admin:assets')) {
				continue;
			}

			$manifest = $this->discoveredManifests[$id] ?? null;
			if ($manifest === null) {
				continue;
			}

			$extPath = $manifest->vendor() . '/' . $manifest->shortName();

			foreach ($context->getRegisteredAdminAssets() as $asset) {
				$url = '/ext/' . $extPath . '/assets/' . ltrim($asset['path'], '/');
				if ($asset['type'] === 'css') {
					$css[] = $url;
				} elseif ($asset['type'] === 'js') {
					$js[] = $url;
				}
			}
		}

		return ['css' => $css, 'js' => $js];
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
					$listeners[$event][] = $listener;
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
	 * Match a request against extension-registered routes.
	 */
	public function matchExtensionRoute(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		$routes = $this->extensionRoutes[$extensionId] ?? [];

		foreach ($routes as $route) {
			if ($route['method'] === $method && $route['path'] === $path) {
				return new ExtensionRoute(handler: $route['handler'], public: $route['public']);
			}
		}

		return null;
	}

	/**
	 * Match a request against extension-registered admin routes.
	 */
	public function matchExtensionAdminRoute(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		$routes = $this->extensionAdminRoutes[$extensionId] ?? [];

		foreach ($routes as $route) {
			if ($route['method'] === $method && $route['path'] === $path) {
				return new ExtensionRoute(handler: $route['handler']);
			}
		}

		return null;
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
			if ($this->container->has(\TotalCMS\Domain\Event\EventDispatcher::class)) {
				/** @var \TotalCMS\Domain\Event\EventDispatcher $dispatcher */
				$dispatcher = $this->container->get(\TotalCMS\Domain\Event\EventDispatcher::class);
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
			// depends on container resolution (page middleware that takes
			// injected services, custom services consumed by Twig functions,
			// etc.) would silently fail to instantiate. Gated by the `container`
			// capability so admins can disable it if they want.
			if ($this->container instanceof \DI\Container && $this->isCapabilityPermitted($id, 'container')) {
				foreach ($context->getRegisteredContainerDefinitions() as $serviceId => $factory) {
					$this->container->set($serviceId, $factory);
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
	 * After a successful register(), update stored permissions to reflect
	 * the extension's current capabilities. New capabilities default to ON,
	 * removed capabilities are pruned, existing choices are preserved.
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
			// First run — set all detected capabilities to ON
			$state->permissions = $capabilities;
		} else {
			// Add new capabilities as ON, preserve existing choices
			foreach (array_keys($capabilities) as $cap) {
				if (!isset($state->permissions[$cap])) {
					$state->permissions[$cap] = true;
				}
			}
			// Remove capabilities the extension no longer uses
			$state->permissions = array_intersect_key($state->permissions, $capabilities);
		}

		if ($state->permissions === $original) {
			return;
		}

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
