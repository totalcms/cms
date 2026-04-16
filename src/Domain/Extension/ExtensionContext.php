<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension;

use Psr\Container\ContainerInterface;
use Slim\Routing\RouteCollectorProxy;
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

	/** @var list<AdminNavItem> */
	private array $adminNavItems = [];

	/** @var list<DashboardWidget> */
	private array $dashboardWidgets = [];

	/** @var array<string,class-string> */
	private array $fieldTypes = [];

	/** @var array<string,list<array{callable, int}>> */
	private array $eventListeners = [];

	/** @var array<string,callable> */
	private array $containerDefinitions = [];

	public function __construct(
		private readonly ExtensionManifest $manifest,
		private readonly string $extensionPath,
		private readonly ContainerInterface $container,
		private readonly ExtensionSettingsManager $settingsManager,
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

		/** @var \TotalCMS\Domain\Schema\Service\SchemaSaver $schemaSaver */
		$schemaSaver = $this->container->get(\TotalCMS\Domain\Schema\Service\SchemaSaver::class);
		$schemaSaver->saveSchema($schemaData);
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
	 * Register routes under the extension's namespace.
	 *
	 * The callable receives a RouteCollectorProxy scoped to /ext/{vendor}/{name}/.
	 */
	public function addRoutes(callable $registrar): void
	{
		$this->routes[] = $registrar;
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
}
