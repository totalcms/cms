<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Automation\Service\AutomationRegistry;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\DashboardWidget;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\FormActionRegistry;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Characterizes everything ExtensionManager::bootAll() wires into core
 * registries, against the REAL container. One injected extension context
 * registers every extension point bootAll() handles; after bootAll() each
 * registry must hold the item. Written before bootAll() was split into
 * boot steps so the split can be verified against it.
 *
 * The context is injected by reflection (the pattern
 * ExtensionCrashProofingTest uses, tests/Integration/ExtensionCrashProofingTest.php:117-152)
 * so no fixture directory or discovery is involved: this test is about
 * wiring, not loading.
 *
 * Adjustments made to the original brief after checking real signatures
 * (see task-1-report.md for file:line detail):
 *  - `StorageFilesystemAdapter` lives at TotalCMS\Domain\Storage\StorageFilesystemAdapter,
 *    not …\Storage\Adapter\StorageFilesystemAdapter (the brief's import path
 *    doesn't exist — verified against ExtensionCrashProofingTest.php's own import
 *    and the file's actual location).
 *  - `AdminNavItem`'s constructor has no `path` param — it's `url`
 *    (src/Domain/Extension/Data/AdminNavItem.php).
 *  - `DashboardWidget`'s constructor param is `label`, not `title`
 *    (src/Domain/Extension/Data/DashboardWidget.php).
 *  - `SearchQuery`'s constructor was never actually exercised by the brief
 *    (only referenced as a type in the anonymous SearchProvider's method
 *    signature), so no adjustment was needed there.
 *  - `Symfony\Component\Console\Command\Command::__construct(?string $name = null, ...)`
 *    accepts a bare name string, so `new Command('wiring:cmd')` is correct as written.
 *  - `SchemaRepository::schemaExists()` DOES see extension schemas — it falls
 *    through fetchDefaultSchema() -> fetchExtensionSchema() -> fetchCustomSchema()
 *    (src/Domain/Schema/Repository/SchemaRepository.php:302-319), so the brief's
 *    direct `schemaExists('wiring-thing')` assertion is correct as written.
 *  - CRITICAL gap the brief did not account for: `bootAll()`'s asset-wiring step
 *    (`collectAssetRecords()`, ExtensionManager.php:1356-1406) looks the
 *    extension's manifest up in the private `$discoveredManifests` map and
 *    `continue`s (registers NOTHING) when it's missing there — it has no
 *    id-based fallback the way the Twig-namespace step does. Injecting only
 *    `contexts` and `loadedExtensions` by reflection (as the brief's steps do)
 *    leaves `$discoveredManifests` empty, so the admin/frontend asset
 *    assertions would fail against the real, untouched manager. Fixed by also
 *    reflection-injecting `discoveredManifests => ['test/wiring' => $manifest]`
 *    in both tests below, using the same manifest instance the context was
 *    built with.
 *  - The brief's `registerMcpResource()`/`registerMcpResourceTemplate()` calls
 *    each passed 4 positional strings before the closure, but both methods'
 *    real signature is `(string $uri/$uriTemplate, string $description,
 *    \Closure $handler, string $access = 'public', ...)` — only ONE
 *    description string, then the handler (ExtensionContext.php:350-408).
 *    The extra string landed in the `$handler` parameter and the closure
 *    landed in `$access`, throwing a TypeError. Dropped the redundant extra
 *    string so the closure lines up with `$handler`.
 *  - `PageMiddlewareRegistry::register()` throws `\InvalidArgumentException`
 *    for any name that doesn't match `/^[a-z0-9][a-z0-9-]*$/` — lowercase,
 *    digits, hyphens only (src/Domain/Builder/Service/PageMiddlewareRegistry.php:47-56).
 *    `bootAll()` catches that exception per-registration and just logs a
 *    warning, so the brief's camelCase `'wiringMiddleware'` silently failed
 *    to register and `has('wiringMiddleware')` came back false. Renamed to
 *    `'wiring-middleware'`.
 */
beforeEach(function (): void {
	// TotalForm::$extensionFieldTypes / $extensionFieldDefaultTypes are
	// process-global statics with no reset API (registerExtensionFieldTypes()
	// only merges). This test registers 'wiringfield' into them; under the
	// parallel suite (sharded by file) a leaked entry would survive into
	// whatever other test file lands in the same worker next, so snapshot
	// and restore.
	$this->savedExtensionFieldTypes        = (new ReflectionProperty(TotalForm::class, 'extensionFieldTypes'))->getValue();
	$this->savedExtensionFieldDefaultTypes = (new ReflectionProperty(TotalForm::class, 'extensionFieldDefaultTypes'))->getValue();

	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();

	// Extension schema directories are Pro+; force the edition so the
	// schema step runs regardless of the test licence. The assertion below
	// on schemaExists('wiring-thing') depends on this mock returning
	// Edition::PRO — SchemaDirectoriesStep::wire() skips entirely (never
	// even calls getAllSchemaDirs()) below Pro, so nothing would be
	// registered on a lesser edition and the assertion would fail.
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturn(true);
	$editions->method('getEdition')->willReturn(Edition::PRO);
	$this->container->set(EditionFeatureService::class, $editions);

	// A real on-disk extension path with templates/ and schemas/ so the
	// template-namespace and schema-directory steps have something to find.
	$this->extPath = sys_get_temp_dir() . '/tcms-boot-wiring-' . uniqid();
	mkdir($this->extPath . '/templates', 0777, true);
	mkdir($this->extPath . '/schemas', 0777, true);
	file_put_contents($this->extPath . '/templates/hello.twig', 'hello from wiring');
	file_put_contents($this->extPath . '/schemas/wiring-thing.json', json_encode([
		'id'         => 'wiring-thing',
		'type'       => 'object',
		'properties' => ['id' => ['type' => 'string', 'field' => 'id']],
	]));

	$this->bootCalled = false;
});

afterEach(function (): void {
	recursiveDelete($this->extPath, forceComplete: true);

	// Restore the TotalForm statics dirtied in beforeEach (see comment there).
	(new ReflectionProperty(TotalForm::class, 'extensionFieldTypes'))->setValue(null, $this->savedExtensionFieldTypes);
	(new ReflectionProperty(TotalForm::class, 'extensionFieldDefaultTypes'))->setValue(null, $this->savedExtensionFieldDefaultTypes);
});

/** Build a manager on the real container with every capability permitted. */
function bootWiringManager(ContainerInterface $container): ExtensionManager
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = sys_get_temp_dir();

	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);
	$stateRepo = new ExtensionStateRepository($storage);

	$settingsStorage = test()->createMock(StorageFilesystemAdapter::class);
	$settingsStorage->method('fileExists')->willReturn(false);
	$settingsManager = new ExtensionSettingsManager($settingsStorage);

	$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));
	$discovery = new ExtensionDiscovery($config, $validator, new NullLogger(), bundledExtensionsDir: $config->datadir . '/no-bundled-here');

	return new ExtensionManager(
		$discovery,
		$stateRepo,
		new ExtensionDependencySorter(),
		$settingsManager,
		$container,
		new NullLogger(),
		$validator,
		testExtensionGuard(),
		testExtensionProfiler(),
	);
}

/** The manifest for extension `test/wiring`, shared between the context and discoveredManifests. */
function bootWiringManifest(): ExtensionManifest
{
	return ExtensionManifest::fromArray(['id' => 'test/wiring', 'name' => 'Wiring']);
}

/** A context for extension `test/wiring` that registers every wired point. */
function bootWiringContext(ContainerInterface $container, string $extPath, ExtensionSettingsManager $settings, ExtensionManifest $manifest): ExtensionContext
{
	$ctx = new ExtensionContext($manifest, $extPath, $container, $settings, new NullLogger());

	$ctx->addRoutes(function ($r): void {
		$r->get('/wiring-ping', fn () => 'pong');
	});
	$ctx->addPublicRoutes(function ($r): void {
		$r->get('/wiring-public', fn () => 'pong');
	});
	$ctx->addAdminRoutes(function ($r): void {
		$r->get('/wiring-admin', fn () => 'pong');
	});
	$ctx->addTwigFunction(new TwigFunction('wiring_fn', fn (): string => 'wired'));
	$ctx->addTwigFilter(new TwigFilter('wiring_filter', fn (string $v): string => strtoupper($v)));
	$ctx->addTwigGlobal('wiringGlobal', 'global-wired');
	$ctx->addCommand(new Command('wiring:cmd'));
	$ctx->addAdminNavItem(new AdminNavItem(label: 'Wiring', icon: 'plug', url: 'wiring'));
	$ctx->addDashboardWidget(new DashboardWidget(id: 'wiring-widget', label: 'Wiring', template: 'w.twig'));
	$ctx->addFieldType('wiringfield', stdClass::class);
	$ctx->addEventListener('wiring.event', static fn () => null);
	$ctx->addAutomation('wiring-auto', 'Wiring automation', [['type' => 'schedule', 'cron' => '0 6 * * *']], static fn () => null);
	$ctx->addPageMiddleware('wiring-middleware', 'Test\\Wiring\\Middleware');
	$ctx->addFormAction('wiring-action', new FormAction('wiring-action', '/wiring/form', 'Wiring form'));
	$ctx->registerMcpTool('wiring_tool', 'A wiring tool', 'admin', static fn () => ['ok' => true]);
	$ctx->registerMcpResource('wiring://thing', 'A wiring resource', static fn () => 'x');
	$ctx->registerMcpResourceTemplate('wiring://items/{id}', 'A wiring template', static fn () => 'x');
	$ctx->registerSearchProvider(new class implements SearchProvider {
		public function id(): string
		{
			return 'wiring-search';
		}

		public function label(): string
		{
			return 'Wiring search';
		}

		public function search(SearchQuery $query): array
		{
			return [];
		}

		public function index(string $collection, string $id, array $data): void
		{
		}

		public function delete(string $collection, string $id): void
		{
		}

		public function isAvailable(): bool
		{
			return true;
		}
	});
	$ctx->addAdminAsset('css', 'wiring-admin.css');
	$ctx->addFrontendAsset('css', 'wiring-front.css');

	return $ctx;
}

test('bootAll wires every extension point into its core registry, and runs boot()', function (): void {
	$manager  = bootWiringManager($this->container);
	$manifest = bootWiringManifest();
	$settings = (new ReflectionProperty(ExtensionManager::class, 'settingsManager'))->getValue($manager);
	$context  = bootWiringContext($this->container, $this->extPath, $settings, $manifest);

	$test      = $this;
	$extension = new class($test) implements ExtensionInterface {
		public function __construct(private object $test)
		{
		}

		public function register(ExtensionContext $context): void
		{
		}

		public function boot(ExtensionContext $context): void
		{
			$this->test->bootCalled = true;
		}
	};

	(new ReflectionProperty(ExtensionManager::class, 'contexts'))->setValue($manager, ['test/wiring' => $context]);
	(new ReflectionProperty(ExtensionManager::class, 'loadedExtensions'))->setValue($manager, ['test/wiring' => $extension]);
	// Required for the asset-wiring step: collectAssetRecords() looks the
	// manifest up here and has no id-based fallback (see docblock above).
	(new ReflectionProperty(ExtensionManager::class, 'discoveredManifests'))->setValue($manager, ['test/wiring' => $manifest]);

	$manager->bootAll();

	// Lifecycle
	expect($this->bootCalled)->toBeTrue();

	// Routes (drained through the guard into the manager's lookup tables)
	expect($manager->matchExtensionRoute('test/wiring', 'GET', '/wiring-ping'))->not->toBeNull()
		->and($manager->matchExtensionRoute('test/wiring', 'GET', '/wiring-public'))->not->toBeNull()
		->and($manager->matchExtensionAdminRoute('test/wiring', 'GET', '/wiring-admin'))->not->toBeNull();

	// Schema directories (Pro+)
	expect($this->container->get(SchemaRepository::class)->schemaExists('wiring-thing'))->toBeTrue();

	// Field types
	expect(TotalForm::getExtensionFieldTypes())->toHaveKey('wiringfield');

	// Event listeners
	expect($this->container->get(EventDispatcher::class)->hasListeners('wiring.event'))->toBeTrue();

	// Automations (keys are prefixed with the extension id; match on the suffix)
	$automationKeys = array_keys($this->container->get(AutomationRegistry::class)->all());
	expect(array_filter($automationKeys, fn (string $k): bool => str_contains($k, 'wiring-auto')))->not->toBeEmpty();

	// Page middleware + form actions
	expect($this->container->get(PageMiddlewareRegistry::class)->has('wiring-middleware'))->toBeTrue()
		->and($this->container->get(FormActionRegistry::class)->get('wiring-action'))->not->toBeNull();

	// MCP tools, resources, resource templates
	expect($this->container->get(ToolRegistry::class)->get('wiring_tool'))->not->toBeNull()
		->and($this->container->get(ResourceRegistry::class)->get('wiring://thing'))->not->toBeNull()
		->and($this->container->get(ResourceRegistry::class)->getTemplate('wiring://items/{id}'))->not->toBeNull();

	// Search providers
	expect($this->container->get(SearchProviderRegistry::class)->get('wiring-search'))->not->toBeNull();

	// Twig: function, filter, global, template namespace, nav/widget globals
	$twig = $this->container->get(TwigEngine::class);
	expect($twig->renderString('{{ wiring_fn() }}'))->toBe('wired')
		->and($twig->renderString('{{ "abc"|wiring_filter }}'))->toBe('ABC')
		->and($twig->renderString('{{ wiringGlobal }}'))->toBe('global-wired')
		->and($twig->renderString('{% include "@test-wiring/hello.twig" %}'))->toBe('hello from wiring')
		->and($twig->renderString('{{ extensionNavItems|length }}'))->toBe('1')
		->and($twig->renderString('{{ extensionDashWidgets|length }}'))->toBe('1');

	// Assets through the CMS adapter
	$adapter = $this->container->get(TotalCMSTwigAdapter::class);
	expect($adapter->adminAssetsHead())->toContain('wiring-admin.css')
		->and($adapter->assetsHead())->toContain('wiring-front.css');
});

test('bootAll is idempotent: a second call wires nothing twice', function (): void {
	$manager  = bootWiringManager($this->container);
	$manifest = bootWiringManifest();
	$settings = (new ReflectionProperty(ExtensionManager::class, 'settingsManager'))->getValue($manager);
	(new ReflectionProperty(ExtensionManager::class, 'contexts'))->setValue($manager, [
		'test/wiring' => bootWiringContext($this->container, $this->extPath, $settings, $manifest),
	]);
	(new ReflectionProperty(ExtensionManager::class, 'discoveredManifests'))->setValue($manager, ['test/wiring' => $manifest]);

	$manager->bootAll();
	$before = count($this->container->get(ToolRegistry::class)->all());
	$manager->bootAll();

	expect(count($this->container->get(ToolRegistry::class)->all()))->toBe($before);
});
