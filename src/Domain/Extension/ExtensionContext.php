<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension;

use Mcp\Schema\ToolAnnotations;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
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

	/** @var array<string,string> Field name => default schema property type */
	private array $fieldDefaultTypes = [];

	/** @var list<array{type: string, path: string, position: ?string, module: bool, preload: bool, version: ?string}> */
	private array $adminAssets = [];

	/** @var list<array{type: string, path: string, position: ?string, module: bool, preload: bool, version: ?string}> */
	private array $frontendAssets = [];

	/** @var array<string,list<array{callable, int}>> */
	private array $eventListeners = [];

	/** @var array<string,callable> */
	private array $containerDefinitions = [];

	/** @var array<string,string> page-middleware name => container service ID */
	private array $pageMiddleware = [];

	/** @var array<string, \TotalCMS\Domain\Extension\Data\FormAction> */
	private array $formActions = [];

	/** @var list<McpToolDefinition> Tools registered for the MCP server */
	private array $mcpTools = [];

	/** @var list<array{uri: string, name: string, description: string, handler: \Closure, access: string, mimeType: string}> Concrete MCP resources */
	private array $mcpResources = [];

	/** @var list<array{uriTemplate: string, name: string, description: string, handler: \Closure, access: string, mimeType: string}> MCP resource templates (URI patterns with {placeholder} segments) */
	private array $mcpResourceTemplates = [];

	/** @var list<\TotalCMS\Domain\Search\Service\SearchProvider> */
	private array $searchProviders = [];

	/** @var list<array{prompt: \Mcp\Schema\Prompt, handler: callable}> Code-defined MCP prompts registered by this extension */
	private array $registeredMcpPrompts = [];

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
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Get the shared extensions logger.
	 *
	 * Writes to tcms-data/logs/extensions.log on the 'extensions' channel.
	 * Prefix messages with the extension id (or your own tag) so multi-extension
	 * logs remain readable.
	 */
	public function logger(): \Psr\Log\LoggerInterface
	{
		return $this->logger;
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
		if (!$this->container->has(SchemaSaver::class)) {
			return;
		}

		$id = (string)($schemaData['id'] ?? '');
		if ($id === '') {
			return;
		}

		// Extension schemas require Pro edition or higher
		if ($this->container->has(EditionFeatureService::class)) {
			$editionService = $this->container->get(EditionFeatureService::class);
			if ($editionService->getEdition()->level() < Edition::PRO->level()) {
				return;
			}
		}

		$schemaRepo = $this->container->get(SchemaRepository::class);

		// Don't overwrite existing schemas
		if ($schemaRepo->schemaExists($id)) {
			return;
		}

		try {
			$schemaSaver = $this->container->get(SchemaSaver::class);
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
	 * Register an MCP tool exposed at /mcp.
	 *
	 * Strict-deny collision policy: a tool whose name conflicts with a core
	 * tool or another extension's tool is logged and skipped. Pick a vendor-
	 * prefixed name (`acme_search_invoices`) to avoid collisions.
	 *
	 * The `handler` closure is invoked by the SDK using PHP reflection on its
	 * named parameters — define typed string/int/bool/array params that map
	 * one-to-one with your `inputSchema` properties.
	 *
	 * @param string                   $name        Tool name (snake_case)
	 * @param string                   $description Description AI agents read
	 * @param string                   $access      'admin' or 'public'
	 * @param \Closure                 $handler     Invoked with named params from MCP call
	 * @param array<string,mixed>|null $inputSchema JSON Schema for the handler's inputs
	 * @param ToolAnnotations|null     $annotations Read/write/destructive hints — mandatory before Anthropic Directory submission
	 */
	public function registerMcpTool(
		string $name,
		string $description,
		string $access,
		\Closure $handler,
		?array $inputSchema = null,
		?ToolAnnotations $annotations = null,
	): void {
		$this->mcpTools[] = new McpToolDefinition(
			name: $name,
			description: $description,
			access: $access,
			handler: $handler,
			inputSchema: $inputSchema,
			annotations: $annotations,
		);
	}

	/**
	 * Register an MCP resource — a concrete `tcms://...` or extension-scheme URI
	 * (e.g. `acme://invoices/all`) that the agent can fetch via `resources/read`
	 * or the `get_resource` tool. The handler is invoked with no arguments and
	 * must return a SDK ResourceContent envelope:
	 *
	 *   ['contents' => [['uri' => '...', 'mimeType' => '...', 'text' => '...']]]
	 *
	 * Collision policy: strict deny. An extension URI that collides with a core
	 * resource OR another extension's resource is logged and skipped by
	 * McpExtensionRegistrar — there is no last-wins fallback. Pick a vendor-
	 * prefixed scheme (`acme://...`) to avoid colliding with `tcms://`.
	 *
	 * @param string   $uri         Concrete URI
	 * @param string   $description Description AI agents read
	 * @param \Closure $handler     Invoked with no args; returns ResourceContent
	 * @param string   $access      'admin', 'public', or 'authenticated' (default 'public')
	 * @param string   $name        Slug-form identifier shown in resources/list — must match `[A-Za-z0-9_-]+`
	 *                              per MCP SDK validation (no spaces). Defaults to the URI when empty.
	 * @param string   $mimeType    Content type the handler will produce
	 */
	public function registerMcpResource(
		string $uri,
		string $description,
		\Closure $handler,
		string $access = 'public',
		string $name = '',
		string $mimeType = 'application/json',
	): void {
		$this->mcpResources[] = [
			'uri'         => $uri,
			'name'        => $name !== '' ? $name : $uri,
			'description' => $description,
			'handler'     => $handler,
			'access'      => $access,
			'mimeType'    => $mimeType,
		];
	}

	/**
	 * Register an MCP resource template — a URI pattern with `{name}` placeholders
	 * (e.g. `acme://invoices/{id}`) that AI agents fill in to construct concrete
	 * resource URIs. Handler receives the substituted segment values as named
	 * arguments matching the template's placeholders.
	 *
	 * Use this when the resource set is unbounded — enumerating thousands of
	 * objects into `resources/list` is impractical, but a single template tells
	 * AI agents the URI shape exists.
	 *
	 * Same collision policy as registerMcpResource(): strict deny.
	 *
	 * @param string   $uriTemplate URI template with `{name}` placeholders
	 * @param string   $description Description AI agents read
	 * @param \Closure $handler     Invoked with named args matching template variables; returns ResourceContent
	 * @param string   $access      'admin', 'public', or 'authenticated' (default 'public')
	 * @param string   $name        Slug-form identifier — must match `[A-Za-z0-9_-]+` per MCP SDK validation
	 *                              (no spaces). Defaults to the template when empty.
	 * @param string   $mimeType    Content type the handler will produce
	 */
	public function registerMcpResourceTemplate(
		string $uriTemplate,
		string $description,
		\Closure $handler,
		string $access = 'public',
		string $name = '',
		string $mimeType = 'application/json',
	): void {
		$this->mcpResourceTemplates[] = [
			'uriTemplate' => $uriTemplate,
			'name'        => $name !== '' ? $name : $uriTemplate,
			'description' => $description,
			'handler'     => $handler,
			'access'      => $access,
			'mimeType'    => $mimeType,
		];
	}

	/**
	 * Register a search provider. The extension is responsible for the provider's
	 * full lifecycle (index, query, delete) and any external dependencies (API
	 * clients, vector stores, etc.). Provider ids must be unique across all
	 * extensions + the built-in 'text' provider; the registrar logs + skips on
	 * collisions during boot.
	 *
	 * Typical use: a Pro-edition-gated provider extension calls this from its
	 * `register()` lifecycle method:
	 *
	 *   public function register(ExtensionContext $context): void {
	 *       if (!$context->editionAllows(EditionFeature::ALGOLIA_SEARCH)) return;
	 *       $context->registerSearchProvider(new AlgoliaSearchProvider(...));
	 *   }
	 */
	public function registerSearchProvider(\TotalCMS\Domain\Search\Service\SearchProvider $provider): void
	{
		$this->searchProviders[] = $provider;
	}

	/**
	 * @return list<\TotalCMS\Domain\Search\Service\SearchProvider>
	 */
	public function getRegisteredSearchProviders(): array
	{
		return $this->searchProviders;
	}

	/**
	 * Register a code-defined MCP prompt. Use when the extension ships the prompt
	 * as PHP code rather than relying on operator-authored objects in the
	 * mcp-prompt collection. The prompt appears in `prompts/list` and is callable
	 * via `prompts/get` on the MCP server.
	 *
	 * Collision policy: soft-deny. If the prompt name collides with a
	 * collection-stored prompt, the extension's prompt is logged and skipped —
	 * collection-stored prompts always win.
	 *
	 * Example:
	 *
	 *   $context->registerMcpPrompt(
	 *       new \Mcp\Schema\Prompt(name: 'audit_links', description: 'Audit broken links on any page.'),
	 *       handler: fn (array $arguments = []) => new \Mcp\Schema\Result\GetPromptResult(
	 *           messages: [new \Mcp\Schema\Content\PromptMessage(
	 *               \Mcp\Schema\Enum\Role::User,
	 *               new \Mcp\Schema\Content\TextContent('Check all links on: ' . ($arguments['url'] ?? '')),
	 *           )],
	 *       ),
	 *   );
	 */
	public function registerMcpPrompt(\Mcp\Schema\Prompt $prompt, callable $handler): void
	{
		$this->registeredMcpPrompts[] = ['prompt' => $prompt, 'handler' => $handler];
	}

	/**
	 * @return list<array{prompt: \Mcp\Schema\Prompt, handler: callable}>
	 */
	public function getRegisteredMcpPrompts(): array
	{
		return $this->registeredMcpPrompts;
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
	 * Defaults: CSS → head, JS → body, JS modules → type="module", preload off,
	 * cache busting via file mtime.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param string             $type     'css' or 'js'
	 * @param string             $path     Path relative to the extension's assets/ directory
	 * @param 'head'|'body'|null $position Override default placement (CSS defaults to head, JS to body)
	 * @param bool               $module   JS only — load as ES module (default true)
	 * @param bool               $preload  Emit a paired <link rel="preload"> in head (default false)
	 * @param string|null        $version  Override cache-busting query string (default: file mtime)
	 */
	public function addAdminAsset(
		string $type,
		string $path,
		?string $position = null,
		bool $module   = true,
		bool $preload  = false,
		?string $version  = null,
	): void {
		$this->adminAssets[] = [
			'type'     => $type,
			'path'     => $path,
			'position' => $position,
			'module'   => $module,
			'preload'  => $preload,
			'version'  => $version,
		];
	}

	/**
	 * Register a CSS or JS asset to be loaded on public pages.
	 *
	 * Defaults: CSS → head, JS → body, JS modules → type="module", preload off,
	 * cache busting via file mtime.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param string             $type     'css' or 'js'
	 * @param string             $path     Path relative to the extension's assets/ directory
	 * @param 'head'|'body'|null $position Override default placement (CSS defaults to head, JS to body)
	 * @param bool               $module   JS only — load as ES module (default true)
	 * @param bool               $preload  Emit a paired <link rel="preload"> in head (default false)
	 * @param string|null        $version  Override cache-busting query string (default: file mtime)
	 */
	public function addFrontendAsset(
		string $type,
		string $path,
		?string $position = null,
		bool $module   = true,
		bool $preload  = false,
		?string $version  = null,
	): void {
		$this->frontendAssets[] = [
			'type'     => $type,
			'path'     => $path,
			'position' => $position,
			'module'   => $module,
			'preload'  => $preload,
			'version'  => $version,
		];
	}

	/**
	 * Register a custom field type.
	 *
	 * @param string $typeName    The field type name (used in schemas)
	 * @param class-string $fqcn  Fully qualified class name extending FormField
	 * @param string $defaultType The default schema property type used when a
	 *                            schema property omits an explicit `type`.
	 *                            Should be one of SchemaData::PROPERTY_TYPES.
	 */
	public function addFieldType(string $typeName, string $fqcn, string $defaultType = 'string'): void
	{
		$this->fieldTypes[$typeName]        = $fqcn;
		$this->fieldDefaultTypes[$typeName] = $defaultType;
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

	/**
	 * Register a per-page middleware that builder pages can opt into via
	 * their `middleware` field. The class must implement
	 * {@see \TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface}
	 * and be resolvable from the container — usually via an
	 * `addContainerDefinition()` call alongside this one.
	 *
	 * @param string $name     Lower-case kebab-case name (e.g. `geo-redirect`)
	 * @param string $serviceId Container service ID — typically the class FQN
	 */
	public function addPageMiddleware(string $name, string $serviceId): void
	{
		$this->pageMiddleware[$name] = $serviceId;
	}

	/**
	 * Register a form action type that the JS form processor can dispatch
	 * to an extension-owned API route. The route must be registered
	 * separately via addRoutes().
	 */
	public function addFormAction(string $name, \TotalCMS\Domain\Extension\Data\FormAction $action): void
	{
		$this->formActions[$name] = $action;
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

	/** @return list<McpToolDefinition> */
	public function getRegisteredMcpTools(): array
	{
		return $this->mcpTools;
	}

	/** @return list<array{uri: string, name: string, description: string, handler: \Closure, access: string, mimeType: string}> */
	public function getRegisteredMcpResources(): array
	{
		return $this->mcpResources;
	}

	/** @return list<array{uriTemplate: string, name: string, description: string, handler: \Closure, access: string, mimeType: string}> */
	public function getRegisteredMcpResourceTemplates(): array
	{
		return $this->mcpResourceTemplates;
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

	/** @return list<array{type: string, path: string, position: ?string, module: bool, preload: bool, version: ?string}> */
	public function getRegisteredAdminAssets(): array
	{
		return $this->adminAssets;
	}

	/** @return list<array{type: string, path: string, position: ?string, module: bool, preload: bool, version: ?string}> */
	public function getRegisteredFrontendAssets(): array
	{
		return $this->frontendAssets;
	}

	/** @return array<string,class-string> */
	public function getRegisteredFieldTypes(): array
	{
		return $this->fieldTypes;
	}

	/** @return array<string,string> */
	public function getRegisteredFieldDefaultTypes(): array
	{
		return $this->fieldDefaultTypes;
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

	/** @return array<string,string> page-middleware name => container service ID */
	public function getRegisteredPageMiddleware(): array
	{
		return $this->pageMiddleware;
	}

	/** @return array<string, \TotalCMS\Domain\Extension\Data\FormAction> */
	public function getRegisteredFormActions(): array
	{
		return $this->formActions;
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
			'twig:functions'  => 'Twig Functions',
			'twig:filters'    => 'Twig Filters',
			'twig:globals'    => 'Twig Globals',
			'routes:api'      => 'API Routes',
			'routes:public'   => 'Public Routes',
			'routes:admin'    => 'Admin Pages',
			'cli:commands'    => 'CLI Commands',
			'admin:nav'       => 'Admin Nav',
			'admin:widgets'   => 'Dash Widgets',
			'admin:assets'    => 'Admin Assets',
			'frontend:assets' => 'Frontend Assets',
			'events:listen'   => 'Event Listeners',
			'fields'          => 'Custom Fields',
			'schemas'         => 'Schemas',
			'container'       => 'Container Defs',
			'page-middleware' => 'Page Middleware',
			'form-actions'    => 'Form Actions',
			'mcp:tools'       => 'MCP Tools',
			'mcp:resources'   => 'MCP Resources',
			'mcp:search'      => 'Search Provider',
			'mcp:prompts'     => 'MCP Prompts',
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
		if ($this->frontendAssets !== []) {
			$caps['frontend:assets'] = true;
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
		if ($this->pageMiddleware !== []) {
			$caps['page-middleware'] = true;
		}
		if ($this->formActions !== []) {
			$caps['form-actions'] = true;
		}
		if (is_dir($this->extensionPath . '/schemas')) {
			$caps['schemas'] = true;
		}
		if ($this->mcpTools !== []) {
			$caps['mcp:tools'] = true;
		}
		if ($this->mcpResources !== [] || $this->mcpResourceTemplates !== []) {
			$caps['mcp:resources'] = true;
		}
		if ($this->searchProviders !== []) {
			$caps['mcp:search'] = true;
		}
		if ($this->registeredMcpPrompts !== []) {
			$caps['mcp:prompts'] = true;
		}

		return $caps;
	}
}
