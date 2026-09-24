<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use DI\CompiledContainer;
use DI\Container;
use Mcp\Schema\Prompt;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ExtensionEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\AutomationDefinition;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\Exception\EntrypointLoadException;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\Boot\AssetsStep;
use TotalCMS\Domain\Extension\Service\Boot\AutomationsStep;
use TotalCMS\Domain\Extension\Service\Boot\EventListenersStep;
use TotalCMS\Domain\Extension\Service\Boot\ExtensionBootStep;
use TotalCMS\Domain\Extension\Service\Boot\FieldTypesStep;
use TotalCMS\Domain\Extension\Service\Boot\FormActionsStep;
use TotalCMS\Domain\Extension\Service\Boot\McpStep;
use TotalCMS\Domain\Extension\Service\Boot\PageMiddlewareStep;
use TotalCMS\Domain\Extension\Service\Boot\SchemaDirectoriesStep;
use TotalCMS\Domain\Extension\Service\Boot\SearchProvidersStep;
use TotalCMS\Domain\Extension\Service\Boot\TwigStep;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use Twig\AbstractTwigCallable;
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

	private readonly ExtensionEntrypointLoader $entrypoints;
	private readonly ExtensionInfoBuilder $info;
	private readonly ExtensionEnableReviewer $reviewer;

	/**
	 * The wiring steps bootAll() runs, in order, after the register/boot
	 * lifecycle. Ordering constraints:
	 *  - McpStep runs before TwigStep so the MCP tool/resource registries
	 *    are already filled by the time the first /mcp request can arrive
	 *    (Twig wiring is what makes the rest of the app, including any
	 *    request that could race an /mcp call, servable).
	 *  - AssetsStep runs last. It was deliberately moved out from inside the
	 *    old monolithic Twig-wiring block, where it ran before
	 *    registerExtensionItems() (functions/filters/globals/nav/widgets).
	 *    The two are independent: AssetsStep only touches
	 *    TotalCMSTwigAdapter's asset lists, TwigStep's registerExtensionItems()
	 *    only touches TwigEngine's callable/global registries, so reordering
	 *    one after the other is safe.
	 *  - Core assets rendering before extension assets is enforced inside
	 *    AssetsStep itself (it registers CoreAdminAssetRegistrar /
	 *    CoreFrontendAssetRegistrar before reading the extension asset
	 *    accessors), not by AssetsStep's position in this list.
	 *
	 * @var list<class-string<ExtensionBootStep>>
	 */
	public const BOOT_STEPS = [
		SchemaDirectoriesStep::class,
		FieldTypesStep::class,
		EventListenersStep::class,
		AutomationsStep::class,
		PageMiddlewareStep::class,
		FormActionsStep::class,
		McpStep::class,
		SearchProvidersStep::class,
		TwigStep::class,
		AssetsStep::class,
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
		$this->entrypoints = new ExtensionEntrypointLoader();
		$this->info        = new ExtensionInfoBuilder($profiler, $guard, $manifestValidator);
		$this->reviewer    = new ExtensionEnableReviewer();
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

		$enrollment = new ExtensionEnrollment(
			$this->stateRepository,
			$this->discovery,
			$this->sorter,
			$this->manifestValidator,
			$this->logger,
		);
		$enrollment->enrol($this->discoveredManifests);

		// Register phase
		foreach ($enrollment->selectForRegister($this->discoveredManifests) as $id => $manifest) {
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
		//
		// Every registrar call is extension code running inside our boot, so each
		// drain goes through the guard: a registrar that throws (the classic case
		// is a callback type-hinted `Slim\Routing\RouteCollectorProxy` instead of
		// our RouteCollector) loses only its own route group instead of taking the
		// whole application down with an uncaught TypeError.
		/** @var array<string,string> extension id => first route-registration failure */
		$routeErrors = [];

		foreach ($this->contexts as $id => $context) {
			$state = $this->stateRepository->getState($id);

			$extRoutes = [];

			// Authenticated API routes
			if (!$state instanceof ExtensionState || $state->isPermitted('routes:api')) {
				$extRoutes = array_merge($extRoutes, $this->collectRoutes($id, 'routes:api', $context->getRegisteredRoutes(), false, $routeErrors));
			}

			// Public routes
			if (!$state instanceof ExtensionState || $state->isPermitted('routes:public')) {
				$extRoutes = array_merge($extRoutes, $this->collectRoutes($id, 'routes:public', $context->getRegisteredPublicRoutes(), true, $routeErrors));
			}

			if ($extRoutes !== []) {
				$this->extensionRoutes[$id] = $extRoutes;
			}

			// Admin routes
			if (!$state instanceof ExtensionState || $state->isPermitted('routes:admin')) {
				$adminRoutes = $this->collectRoutes($id, 'routes:admin', $context->getRegisteredAdminRoutes(), false, $routeErrors);

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

		// Surface route-registration failures on the extension AFTER the boot
		// loop: a successful boot() calls clearError(), which would otherwise
		// wipe an error recorded during the route drain above.
		foreach ($routeErrors as $id => $message) {
			$this->stateRepository->recordError($id, $message);
		}

		foreach (self::BOOT_STEPS as $stepClass) {
			(new $stepClass($this->container, $this->logger))->wire($this);
		}

		$this->booted = true;
	}

	/**
	 * Run one extension's route registrars and collect what they declared.
	 *
	 * Each registrar is guarded individually: a throwing registrar contributes
	 * no routes, is logged and counted toward auto-quarantine like any other
	 * extension hook, and surfaces as an error on the extension in the admin —
	 * but the drain continues and the rest of the application boots normally.
	 *
	 * @param list<callable>       $registrars
	 * @param array<string,string> $errors Collects "extension id => message" for the caller to
	 *                                     record on state once the boot loop is done
	 *
	 * @return list<array{method: string, path: string, handler: mixed, public: bool, permission: string|null}>
	 */
	private function collectRoutes(string $id, string $hookType, array $registrars, bool $isPublic, array &$errors): array
	{
		$routes = [];

		foreach ($registrars as $registrar) {
			$collector = new RouteCollector(isPublic: $isPublic);

			$collected = $this->guard->run(
				$id,
				$hookType,
				function () use ($registrar, $collector): array {
					$registrar($collector);

					return $collector->getRoutes();
				},
				fallback: null,
			);

			if ($collected === null) {
				$errors[$id] ??= "{$hookType} registration failed — see the extensions log for the full error.";

				continue;
			}

			$routes = array_merge($routes, $collected);
		}

		return $routes;
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

			// A human just explicitly consented — this is no longer an unreviewed
			// auto-enrolled record, regardless of how it started. Clears the
			// provenance marker discoverAndRegister() uses to stop auto-written
			// consent from transferring to a later-resolved non-bundled manifest.
			$state->autoEnrolledBundled = false;

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
					$state->permissions[$cap] ??= true;
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
		$manifest = $this->discoveredManifests[$extensionId] ?? null;

		return $this->stateRepository->isEnabled($extensionId, $manifest);
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
	 *   hasFlags: bool,
	 *   skill: array{target:string,contents:string,files:list<string>}|null
	 * }
	 */
	public function getEnableReview(string $extensionId): array
	{
		$manifest = $this->discoveredManifests[$extensionId] ?? null;
		if ($manifest === null) {
			return ['capabilities' => [], 'findings' => [], 'reviewNote' => '', 'risky' => [], 'hasFlags' => false, 'skill' => null];
		}

		try {
			$capabilities = $this->detectCapabilities($extensionId);
		} catch (\Throwable $e) {
			$this->logger->warning("getEnableReview capability detection failed for '{$extensionId}': " . $e->getMessage());
			$capabilities = [];
		}

		return $this->reviewer->review($extensionId, $manifest, $capabilities, $this->discovery->getExtensionPath($extensionId));
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
			$extensions[] = $this->info->build($id, $manifest, $states[$id] ?? null, $this->discovery->getExtensionPath($id), $capabilityLabels);
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

		return $this->info->build($extensionId, $manifest, $state, $this->discovery->getExtensionPath($extensionId), ExtensionContext::capabilityLabels());
	}

	// -------------------------------------------------------------------------
	// Accessors for collected registrations (filtered by permissions)
	// -------------------------------------------------------------------------

	/** @return list<TwigFunction> */
	public function getAllTwigFunctions(): array
	{
		$functions = [];
		foreach ($this->permittedContexts('twig:functions') as $id => $context) {
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
	 * @return array<string,list<McpToolDefinition>>
	 */
	public function getAllMcpTools(): array
	{
		$byExtension = [];
		foreach ($this->permittedContexts('mcp:tools') as $id => $context) {
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
	 * @return array<string,list<McpResourceDefinition>>
	 */
	public function getAllMcpResources(): array
	{
		$byExtension = [];
		foreach ($this->permittedContexts('mcp:resources') as $id => $context) {
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
	 * @return array<string,list<McpResourceTemplateDefinition>>
	 */
	public function getAllMcpResourceTemplates(): array
	{
		$byExtension = [];
		foreach ($this->permittedContexts('mcp:resources') as $id => $context) {
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
	 * @return array<string,list<SearchProvider>>
	 */
	public function getAllMcpSearchProviders(): array
	{
		$byExtension = [];
		foreach ($this->permittedContexts('mcp:search') as $id => $context) {
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
	 * @return array<string,list<array{prompt: Prompt, handler: callable, access: string}>>
	 */
	public function getAllMcpPrompts(): array
	{
		$byExtension = [];
		foreach ($this->permittedContexts('mcp:prompts') as $id => $context) {
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
		foreach ($this->permittedContexts('twig:filters') as $id => $context) {
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
		$prop = new \ReflectionProperty(AbstractTwigCallable::class, 'options');
		/** @var array<string,mixed> $options */
		$options = $prop->getValue($callable);

		return $options;
	}

	/** @return array<string,mixed> */
	public function getAllTwigGlobals(): array
	{
		$globals = [];
		foreach ($this->permittedContexts('twig:functions') as $context) {
			$globals = array_merge($globals, $context->getRegisteredTwigGlobals());
		}

		return $globals;
	}

	/** @return list<Command> */
	public function getAllCommands(): array
	{
		$commands = [];
		foreach ($this->permittedContexts('cli:commands') as $context) {
			$commands = array_merge($commands, $context->getRegisteredCommands());
		}

		return $commands;
	}

	/** @return list<AdminNavItem> */
	public function getAllAdminNavItems(): array
	{
		$items = [];
		foreach ($this->permittedContexts('admin:nav') as $id => $context) {
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
		foreach ($this->permittedContexts('admin:widgets') as $id => $context) {
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

	/**
	 * Extension schema directories to register on the SchemaRepository:
	 * extension id => `<extensionPath>/schemas`, for permitted `schemas`
	 * extensions whose directory exists.
	 *
	 * @return array<string,string>
	 */
	public function getAllSchemaDirs(): array
	{
		$dirs = [];
		foreach ($this->permittedContexts('schemas') as $id => $context) {
			$schemasDir = $context->extensionPath() . '/schemas';
			if (is_dir($schemasDir)) {
				$dirs[$id] = $schemasDir;
			}
		}

		return $dirs;
	}

	/**
	 * Twig template namespaces: `vendor-name` => `<extensionPath>/templates`
	 * for every loaded extension whose directory exists. Not permission
	 * gated (it never was: a template namespace exposes nothing by itself).
	 *
	 * The map is keyed by namespace, not by extension id, so two extensions
	 * whose ids normalize to the same `vendor-name` string collide and the
	 * later one (iteration order of `$this->contexts`) silently wins — the
	 * old inline loop this replaced had the same collision, since it also
	 * appended both paths to one shared namespace key.
	 *
	 * @return array<string,string>
	 */
	public function getAllTemplatePaths(): array
	{
		$paths = [];
		foreach ($this->contexts as $id => $context) {
			$templatesDir = $context->extensionPath() . '/templates';
			if (!is_dir($templatesDir)) {
				continue;
			}
			$manifest  = $this->discoveredManifests[$id] ?? null;
			$namespace = $manifest !== null
				? $manifest->vendor() . '-' . $manifest->shortName()
				: str_replace('/', '-', $id);
			$paths[$namespace] = $templatesDir;
		}

		return $paths;
	}

	/**
	 * Page middleware registrations, gated by `page-middleware`:
	 * extension id => (name => service id).
	 *
	 * @return array<string,array<string,string>>
	 */
	public function getAllPageMiddleware(): array
	{
		$all = [];
		foreach ($this->permittedContexts('page-middleware') as $id => $context) {
			$registered = $context->getRegisteredPageMiddleware();
			if ($registered !== []) {
				$all[$id] = $registered;
			}
		}

		return $all;
	}

	/**
	 * Form-action registrations, gated by `form-actions`:
	 * extension id => list of FormAction.
	 *
	 * @return array<string,list<FormAction>>
	 */
	public function getAllFormActions(): array
	{
		$all = [];
		foreach ($this->permittedContexts('form-actions') as $id => $context) {
			$actions = $context->getRegisteredFormActions();
			if ($actions !== []) {
				$all[$id] = array_values($actions);
			}
		}

		return $all;
	}

	/** @return array<string,class-string> */
	public function getAllFieldTypes(): array
	{
		$types = [];
		foreach ($this->permittedContexts('fields') as $context) {
			$types = array_merge($types, $context->getRegisteredFieldTypes());
		}

		return $types;
	}

	/** @return array<string,string> */
	public function getAllFieldDefaultTypes(): array
	{
		$types = [];
		foreach ($this->permittedContexts('fields') as $context) {
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
	public function getAllAdminAssets(): array
	{
		return $this->collectAssetRecords('admin:assets', fn (ExtensionContext $context): array => $context->getRegisteredAdminAssets());
	}

	/**
	 * Get all frontend asset records for extensions with the
	 * frontend:assets capability enabled.
	 *
	 * @return list<FrontendAsset>
	 */
	public function getAllFrontendAssets(): array
	{
		return $this->collectAssetRecords('frontend:assets', fn (ExtensionContext $context): array => $context->getRegisteredFrontendAssets());
	}

	/**
	 * Collect asset records from contexts, building servable URLs with
	 * mtime-based cache busting and applying position defaults.
	 *
	 * @param callable(ExtensionContext): list<array{type: string, path: string, position: ?string, module: bool, preload: bool, version: ?string, attributes: array<string,string>}> $registrationsFor
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
				if ($asset['type'] === 'meta') {
					$records[] = new FrontendAsset(type: 'meta', url: '', position: 'head', attributes: $asset['attributes']);
					continue;
				}
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
		foreach ($this->permittedContexts('events:listen') as $id => $context) {
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
	 * @return array<string,AutomationDefinition>
	 */
	public function getAllAutomations(): array
	{
		$automations = [];
		foreach ($this->permittedContexts('automations') as $id => $context) {
			foreach ($context->getRegisteredAutomations() as $automation) {
				$automations["{$id}:{$automation->id}"] = $automation;
			}
		}

		return $automations;
	}

	/**
	 * Every extension automation for the admin, including ones the `automations`
	 * capability currently forbids.
	 *
	 * Separate from getAllAutomations() on purpose. That accessor is the dispatch
	 * path and must omit what is not permitted; this one is the status view, and
	 * omitting a disabled automation there would make it disappear from the admin
	 * entirely — the same blindness as not listing extension automations at all,
	 * one toggle away. So permission becomes a flag rather than a filter.
	 *
	 * @return list<array{key:string,extension:string,id:string,label:string,triggers:list<array<string,mixed>>,permitted:bool}>
	 */
	public function listAutomationsForAdmin(): array
	{
		$rows = [];
		foreach ($this->contexts as $extensionId => $context) {
			$permitted = $this->isCapabilityPermitted($extensionId, 'automations');
			foreach ($context->getRegisteredAutomations() as $automation) {
				$rows[] = [
					'key'       => "{$extensionId}:{$automation->id}",
					'extension' => $extensionId,
					'id'        => $automation->id,
					'label'     => $automation->label,
					'triggers'  => $automation->triggers,
					'permitted' => $permitted,
				];
			}
		}

		return $rows;
	}

	/**
	 * Match a request against extension-registered routes.
	 */
	public function matchExtensionRoute(string $extensionId, string $method, string $path): ?ExtensionRoute
	{
		$match = ExtensionRouteMatcher::resolve($this->extensionRoutes[$extensionId] ?? [], $method, $path);
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
		$match = ExtensionRouteMatcher::resolve($this->extensionAdminRoutes[$extensionId] ?? [], $method, $path);
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
	 * The contexts of loaded extensions that are permitted `$capability`,
	 * keyed by extension id. Every getAll*() accessor iterates this instead
	 * of repeating the permission check.
	 *
	 * @return \Generator<string,ExtensionContext>
	 */
	private function permittedContexts(string $capability): \Generator
	{
		foreach ($this->contexts as $id => $context) {
			if ($this->isCapabilityPermitted($id, $capability)) {
				yield $id => $context;
			}
		}
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

		try {
			$className  = $this->entrypoints->load($manifest, $extPath);
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
			if ($this->container->has(EventDispatcher::class)) {
				/** @var EventDispatcher $dispatcher */
				$dispatcher = $this->container->get(EventDispatcher::class);
				$dispatcher->dispatch($event, $payload);
			}
		} catch (\Throwable) {
			// Don't let event dispatch failures affect extension management
		}
	}

	private function registerExtension(string $id, ExtensionManifest $manifest): void
	{
		$extPath = $this->discovery->getExtensionPath($id);
		if ($extPath === null) {
			$this->logger->error("Extension '{$id}' directory not found");

			return;
		}

		try {
			$className = $this->entrypoints->load($manifest, $extPath);
		} catch (EntrypointLoadException $e) {
			$this->logger->error("Extension '{$id}': " . $e->getMessage());
			$this->stateRepository->recordError($id, $e->getMessage());

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
			if ($this->container instanceof Container) {
				$compiled = $this->container instanceof CompiledContainer;
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
				$state->permissions[$cap] ??= in_array($cap, ExtensionContext::ALWAYS_ON_CAPABILITIES, true);
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
}
