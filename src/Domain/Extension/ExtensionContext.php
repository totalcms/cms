<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The stable API surface for extensions.
 *
 * Extensions interact with Total CMS exclusively through this object.
 * It wraps the DI container and provides curated, versionable methods
 * so that internal changes don't break third-party extensions.
 */
final class ExtensionContext
{
	/** @var list<TwigFunction> */
	private array $twigFunctions = [];

	/** @var list<TwigFilter> */
	private array $twigFilters = [];

	/** @var array<string,mixed> */
	private array $twigGlobals = [];

	/** @var list<Command> */
	private array $commands = [];

	/** @var list<callable> */
	private array $routes = [];

	/** @var list<callable> */
	private array $publicRoutes = [];

	/** @var list<callable> */
	private array $adminRoutes = [];

	/** @var list<AdminNavItem> */
	private array $adminNavItems = [];

	/** @var list<DashboardWidget> */
	private array $dashboardWidgets = [];

	/** @var array<string,class-string> */
	private array $fieldTypes = [];

	/** @var list<array{type: string, path: string}> */
	private array $adminAssets = [];

	/** @var array<string,list<array{callable, int}>> */
	private array $eventListeners = [];

	/** @var array<string,callable> */
	private array $containerDefinitions = [];

	public function __construct(
		private readonly ExtensionManifest $manifest,
		private readonly string $extensionPath,
		private readonly ContainerInterface $container,
		private readonly ExtensionSettingsManager $settingsManager,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function extensionId(): string
	{
		return $this->manifest->id;
	}

	public function extensionPath(): string
	{
		return $this->extensionPath;
	}

	public function manifest(): ExtensionManifest
	{
		return $this->manifest;
	}

	// -------------------------------------------------------------------------
	// Settings (per-extension config stored in tcms-data)
	// -------------------------------------------------------------------------

	/** @return array<string,mixed> */
	public function settings(): array
	{
		return $this->settingsManager->getSettings($this->manifest->id);
	}

	public function setting(string $key, mixed $default = null): mixed
	{
		return $this->settingsManager->getSetting($this->manifest->id, $key, $default);
	}

	// -------------------------------------------------------------------------
	// Service resolution (boot phase only)
	// -------------------------------------------------------------------------

	public function get(string $serviceId): mixed
	{
		return $this->container->get($serviceId);
	}

	public function has(string $serviceId): bool
	{
		return $this->container->has($serviceId);
	}

	/**
	 * Install a user-editable schema into tcms-data/.schemas/.
	 *
	 * Use this for schemas the user should be able to customize.
	 * For read-only schemas managed by the extension, place them in the
	 * extension's schemas/ directory instead.
	 *
	 * Only works on Pro edition or higher. Skips silently if schema already exists.
	 *
	 * @param array<string,mixed> $schemaData Schema data with at least 'id' and 'properties'
	 */
	public function installSchema(array $schemaData): void
	{
		if (!$this->container->has(\TotalCMS\Domain\Schema\Service\SchemaSaver::class)) {
			return;
		}

		$id = (string)($schemaData['id'] ?? '');
		if ($id === '') {
			return;
		}

		/** @var \TotalCMS\Domain\Schema\Repository\SchemaRepository $schemaRepo */
		$schemaRepo = $this->container->get(\TotalCMS\Domain\Schema\Repository\SchemaRepository::class);

		// Don't overwrite existing schemas
		if ($schemaRepo->schemaExists($id)) {
			return;
		}

		try {
			/** @var \TotalCMS\Domain\Schema\Service\SchemaSaver $schemaSaver */
			$schemaSaver = $this->container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);
			$schemaSaver->saveSchema($schemaData);
		} catch (\Throwable $e) {
			$this->logger->error("installSchema failed for '{$id}': " . $e->getMessage());
		}
	}

	// -------------------------------------------------------------------------
	// Registration methods (register phase)
	// -------------------------------------------------------------------------

	public function addTwigFunction(TwigFunction $function): void
	{
		$this->twigFunctions[] = $function;
	}

	public function addTwigFilter(TwigFilter $filter): void
	{
		$this->twigFilters[] = $filter;
	}

	public function addTwigGlobal(string $name, mixed $value): void
	{
		$this->twigGlobals[$name] = $value;
	}

	public function addCommand(Command $command): void
	{
		$this->commands[] = $command;
	}

	/**
	 * Register API routes under /ext/{vendor}/{name}/.
	 *
	 * These routes require authentication (session or API key).
	 */
	public function addRoutes(callable $registrar): void
	{
		$this->routes[] = $registrar;
	}

	/**
	 * Register public routes under /ext/{vendor}/{name}/.
	 *
	 * These routes have NO authentication — use for webhooks, embeds,
	 * and endpoints that must be accessible without credentials.
	 */
	public function addPublicRoutes(callable $registrar): void
	{
		$this->publicRoutes[] = $registrar;
	}

	/**
	 * Register admin routes under /admin/ext/{vendor}/{name}/.
	 *
	 * These routes are protected by admin auth middleware.
	 * Templates can extend admin-dashboard.twig for the admin layout.
	 */
	public function addAdminRoutes(callable $registrar): void
	{
		$this->adminRoutes[] = $registrar;
	}

	public function addAdminNavItem(AdminNavItem $item): void
	{
		$this->adminNavItems[] = $item;
	}

	public function addDashboardWidget(DashboardWidget $widget): void
	{
		$this->dashboardWidgets[] = $widget;
	}

	/**
	 * Register a CSS or JS asset to be loaded in the admin.
	 *
	 * @param string $type 'css' or 'js'
	 * @param string $path Path relative to the extension's assets/ directory
	 */
	public function addAdminAsset(string $type, string $path): void
	{
		$this->adminAssets[] = ['type' => $type, 'path' => $path];
	}

	/**
	 * Register a custom field type.
	 *
	 * @param string $typeName The field type name (used in schemas)
	 * @param class-string $fqcn Fully qualified class name extending FormField
	 */
	public function addFieldType(string $typeName, string $fqcn): void
	{
		$this->fieldTypes[$typeName] = $fqcn;
	}

	/**
	 * Subscribe to a content event.
	 *
	 * @param string   $eventName e.g. 'object.created', 'schema.saved'
	 * @param callable $listener  Receives array $payload
	 * @param int      $priority  Lower = earlier (default 0)
	 */
	public function addEventListener(string $eventName, callable $listener, int $priority = 0): void
	{
		$this->eventListeners[$eventName][] = [$listener, $priority];
	}

	/**
	 * Register an additional container definition.
	 *
	 * @param string   $id      Service identifier (typically a class name)
	 * @param callable $factory Factory closure: fn(ContainerInterface) => object
	 */
	public function addContainerDefinition(string $id, callable $factory): void
	{
		$this->containerDefinitions[$id] = $factory;
	}

	// -------------------------------------------------------------------------
	// Getters (used by ExtensionManager to collect registrations)
	// -------------------------------------------------------------------------

	/** @return list<TwigFunction> */
	public function getRegisteredTwigFunctions(): array
	{
		return $this->twigFunctions;
	}

	/** @return list<TwigFilter> */
	public function getRegisteredTwigFilters(): array
	{
		return $this->twigFilters;
	}

	/** @return array<string,mixed> */
	public function getRegisteredTwigGlobals(): array
	{
		return $this->twigGlobals;
	}

	/** @return list<Command> */
	public function getRegisteredCommands(): array
	{
		return $this->commands;
	}

	/** @return list<callable> */
	public function getRegisteredRoutes(): array
	{
		return $this->routes;
	}

	/** @return list<callable> */
	public function getRegisteredPublicRoutes(): array
	{
		return $this->publicRoutes;
	}

	/** @return list<callable> */
	public function getRegisteredAdminRoutes(): array
	{
		return $this->adminRoutes;
	}

	/** @return list<AdminNavItem> */
	public function getRegisteredAdminNavItems(): array
	{
		return $this->adminNavItems;
	}

	/** @return list<DashboardWidget> */
	public function getRegisteredDashboardWidgets(): array
	{
		return $this->dashboardWidgets;
	}

	/** @return list<array{type: string, path: string}> */
	public function getRegisteredAdminAssets(): array
	{
		return $this->adminAssets;
	}

	/** @return array<string,class-string> */
	public function getRegisteredFieldTypes(): array
	{
		return $this->fieldTypes;
	}

	/** @return array<string,list<array{callable, int}>> */
	public function getRegisteredEventListeners(): array
	{
		return $this->eventListeners;
	}

	/** @return array<string,callable> */
	public function getRegisteredContainerDefinitions(): array
	{
		return $this->containerDefinitions;
	}

	// -------------------------------------------------------------------------
	// Capability detection
	// -------------------------------------------------------------------------

	/**
	 * Capability keys and their human-readable labels.
	 *
	 * @return array<string,string>
	 */
	public static function capabilityLabels(): array
	{
		return [
			'twig:functions' => 'Twig Functions',
			'twig:filters'   => 'Twig Filters',
			'routes:api'     => 'API Routes',
			'routes:public'  => 'Public Routes',
			'routes:admin'   => 'Admin Pages',
			'cli:commands'   => 'CLI Commands',
			'admin:nav'      => 'Admin Navigation',
			'admin:widgets'  => 'Dashboard Widgets',
			'admin:assets'   => 'Admin Assets',
			'events:listen'  => 'Event Listeners',
			'fields'         => 'Custom Fields',
			'schemas'        => 'Schemas',
			'container'      => 'Container Definitions',
		];
	}

	/**
	 * Detect which capabilities this extension actually registered.
	 *
	 * Returns only capabilities that have at least one registration.
	 *
	 * @return array<string,bool> Capability key => true
	 */
	public function getCapabilities(): array
	{
		$caps = [];

		if ($this->twigFunctions !== []) {
			$caps['twig:functions'] = true;
		}
		if ($this->twigFilters !== []) {
			$caps['twig:filters'] = true;
		}
		if ($this->twigGlobals !== []) {
			$caps['twig:globals'] = true;
		}
		if ($this->commands !== []) {
			$caps['cli:commands'] = true;
		}
		if ($this->routes !== []) {
			$caps['routes:api'] = true;
		}
		if ($this->publicRoutes !== []) {
			$caps['routes:public'] = true;
		}
		if ($this->adminRoutes !== []) {
			$caps['routes:admin'] = true;
		}
		if ($this->adminNavItems !== []) {
			$caps['admin:nav'] = true;
		}
		if ($this->dashboardWidgets !== []) {
			$caps['admin:widgets'] = true;
		}
		if ($this->adminAssets !== []) {
			$caps['admin:assets'] = true;
		}
		if ($this->eventListeners !== []) {
			$caps['events:listen'] = true;
		}
		if ($this->fieldTypes !== []) {
			$caps['fields'] = true;
		}
		if ($this->containerDefinitions !== []) {
			$caps['container'] = true;
		}

		return $caps;
	}
}
