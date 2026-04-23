<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
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

			if (!$this->meetsEditionRequirement($manifest)) {
				$this->logger->info("Extension '{$id}' requires edition '{$manifest->minEdition}', skipping");
				$this->stateRepository->recordError(
					$id,
					"Requires {$manifest->minEdition} edition or higher",
				);

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
			$extRoutes = [];

			// Authenticated API routes
			foreach ($context->getRegisteredRoutes() as $registrar) {
				$collector = new RouteCollector(isPublic: false);
				$registrar($collector);
				$extRoutes = array_merge($extRoutes, $collector->getRoutes());
			}

			// Public routes
			foreach ($context->getRegisteredPublicRoutes() as $registrar) {
				$collector = new RouteCollector(isPublic: true);
				$registrar($collector);
				$extRoutes = array_merge($extRoutes, $collector->getRoutes());
			}

			if ($extRoutes !== []) {
				$this->extensionRoutes[$id] = $extRoutes;
			}

			// Admin routes
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
		if ($this->container->has(\TotalCMS\Domain\Schema\Repository\SchemaRepository::class)) {
			$this->registerExtensionSchemas();
		}

		// Register extension field types in the form builder and schema property editor
		$extFieldTypes = $this->getAllFieldTypes();
		if ($extFieldTypes !== []) {
			\TotalCMS\Domain\Admin\TotalForm::registerExtensionFieldTypes($extFieldTypes);
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
		$state = $this->stateRepository->getState($extensionId);
		if (!$state instanceof ExtensionState) {
			$state = new ExtensionState(
				enabled: true,
				installedAt: date('c'),
				version: $this->discoveredManifests[$extensionId]->version ?? '0.0.0',
			);
		} else {
			$state->enabled = true;
			$state->error   = null;
		}

		$this->stateRepository->saveState($extensionId, $state);
		$this->dispatchEvent('extension.enabled', ['id' => $extensionId]);
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
			$this->dispatchEvent('extension.disabled', ['id' => $extensionId]);
		}
	}

	// -------------------------------------------------------------------------
	// Accessors for collected registrations
	// -------------------------------------------------------------------------

	/** @return list<TwigFunction> */
	public function getAllTwigFunctions(): array
	{
		$functions = [];
		foreach ($this->contexts as $context) {
			$functions = array_merge($functions, $context->getRegisteredTwigFunctions());
		}

		return $functions;
	}

	/** @return list<TwigFilter> */
	public function getAllTwigFilters(): array
	{
		$filters = [];
		foreach ($this->contexts as $context) {
			$filters = array_merge($filters, $context->getRegisteredTwigFilters());
		}

		return $filters;
	}

	/** @return array<string,mixed> */
	public function getAllTwigGlobals(): array
	{
		$globals = [];
		foreach ($this->contexts as $context) {
			$globals = array_merge($globals, $context->getRegisteredTwigGlobals());
		}

		return $globals;
	}

	/** @return list<Command> */
	public function getAllCommands(): array
	{
		$commands = [];
		foreach ($this->contexts as $context) {
			$commands = array_merge($commands, $context->getRegisteredCommands());
		}

		return $commands;
	}

	/** @return list<AdminNavItem> */
	public function getAllAdminNavItems(): array
	{
		$items = [];
		foreach ($this->contexts as $context) {
			$items = array_merge($items, $context->getRegisteredAdminNavItems());
		}

		usort($items, fn (AdminNavItem $a, AdminNavItem $b): int => $a->priority <=> $b->priority);

		return $items;
	}

	/** @return list<DashboardWidget> */
	public function getAllDashboardWidgets(): array
	{
		$widgets = [];
		foreach ($this->contexts as $context) {
			$widgets = array_merge($widgets, $context->getRegisteredDashboardWidgets());
		}

		usort($widgets, fn (DashboardWidget $a, DashboardWidget $b): int => $a->priority <=> $b->priority);

		return $widgets;
	}

	/** @return array<string,class-string> */
	public function getAllFieldTypes(): array
	{
		$types = [];
		foreach ($this->contexts as $context) {
			$types = array_merge($types, $context->getRegisteredFieldTypes());
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
		foreach ($this->contexts as $context) {
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
	 * @param array<string,mixed> $payload
	 */
	private function dispatchEvent(string $event, array $payload): void
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
		try {
			if ($this->container->has(\TotalCMS\Domain\License\Service\EditionFeatureService::class)) {
				/** @var \TotalCMS\Domain\License\Service\EditionFeatureService $editionService */
				$editionService = $this->container->get(\TotalCMS\Domain\License\Service\EditionFeatureService::class);
				$edition        = $editionService->getEdition();

				if ($edition->level() < \TotalCMS\Domain\License\Data\Edition::PRO->level()) {
					return; // Below Pro — skip extension schemas
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Edition check failed for extension schemas: ' . $e->getMessage());
			// Fail open — allow schemas if we can't determine the edition
		}

		/** @var \TotalCMS\Domain\Schema\Repository\SchemaRepository $schemaRepo */
		$schemaRepo = $this->container->get(\TotalCMS\Domain\Schema\Repository\SchemaRepository::class);

		foreach ($this->contexts as $id => $context) {
			$schemasDir = $context->extensionPath() . '/schemas';
			if (is_dir($schemasDir)) {
				$schemaRepo->registerExtensionSchemaDir($schemasDir);
				$this->logger->debug("Registered extension schemas from '{$id}'");
			}
		}
	}

	private function meetsEditionRequirement(ExtensionManifest $manifest): bool
	{
		$requiredEdition = $manifest->minEdition;
		if ($requiredEdition === '' || $requiredEdition === 'lite') {
			return true; // No restriction or lowest tier
		}

		try {
			if (!$this->container->has(\TotalCMS\Domain\License\Service\EditionFeatureService::class)) {
				return true; // Edition service not available, allow
			}

			/** @var \TotalCMS\Domain\License\Service\EditionFeatureService $editionService */
			$editionService = $this->container->get(\TotalCMS\Domain\License\Service\EditionFeatureService::class);
			$currentEdition = $editionService->getEdition();
			$required       = \TotalCMS\Domain\License\Data\Edition::fromString($requiredEdition);

			return $currentEdition->level() >= $required->level();
		} catch (\Throwable) {
			return true; // Fail open — don't block extensions if edition check fails
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

			$this->loadedExtensions[$id] = $extension;
			$this->contexts[$id]         = $context;
			$this->stateRepository->clearError($id);
		} catch (\Throwable $e) {
			$this->logger->error("Extension '{$id}' failed in register(): {$e->getMessage()}", [
				'exception' => $e,
			]);
			$this->stateRepository->recordError($id, 'register() failed: ' . $e->getMessage());
		}
	}

	/**
	 * Extract the fully qualified class name from a PHP file.
	 */
	private function resolveClassName(string $filePath): ?string
	{
		$contents = file_get_contents($filePath);
		if ($contents === false) {
			return null;
		}

		$namespace = '';
		$class     = '';

		if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
			$namespace = trim($matches[1]);
		}

		if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
			$class = $matches[1];
		}

		if ($class === '') {
			return null;
		}

		return $namespace !== '' ? $namespace . '\\' . $class : $class;
	}
}
