<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
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
	 *
	 * @param App<ContainerInterface> $app
	 */
	public function bootAll(App $app): void
	{
		if ($this->booted) {
			return;
		}

		// Register extension routes
		foreach ($this->contexts as $id => $context) {
			$routes = $context->getRegisteredRoutes();
			if ($routes !== []) {
				$manifest = $this->discoveredManifests[$id] ?? null;
				if ($manifest !== null) {
					$routePrefix = '/ext/' . $manifest->vendor() . '/' . $manifest->shortName();
					$app->group($routePrefix, function (RouteCollectorProxy $group) use ($routes): void {
						foreach ($routes as $registrar) {
							$registrar($group);
						}
					});
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
		if ($this->container->has(\TotalCMS\Domain\Schema\Repository\SchemaRepository::class)) {
			$this->registerExtensionSchemas();
		}

		// Wire event listeners from extensions into the EventDispatcher
		if ($this->container->has(\TotalCMS\Domain\Extension\Event\EventDispatcher::class)) {
			$eventListeners = $this->getAllEventListeners();
			if ($eventListeners !== []) {
				/** @var \TotalCMS\Domain\Extension\Event\EventDispatcher $dispatcher */
				$dispatcher = $this->container->get(\TotalCMS\Domain\Extension\Event\EventDispatcher::class);
				$dispatcher->registerAll($eventListeners);
			}
		}

		// Wire Twig items from extensions into the TwigEngine (with collision protection)
		if ($this->container->has(\TotalCMS\Domain\Twig\Service\TwigEngine::class)) {
			$twigRegistrar = new TwigExtensionRegistrar($this->logger);
			$twigRegistrar->filterAndRegister(
				$this->container->get(\TotalCMS\Domain\Twig\Service\TwigEngine::class),
				$this->container->get(\TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension::class),
				$this->getAllTwigFunctions(),
				$this->getAllTwigFilters(),
				$this->getAllTwigGlobals(),
			);
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
	}

	public function disable(string $extensionId): void
	{
		$state = $this->stateRepository->getState($extensionId);
		if ($state instanceof ExtensionState) {
			$state->enabled = false;
			$state->error   = null;
			$this->stateRepository->saveState($extensionId, $state);
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
