<?php

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Random\RandomException;
use Slim\App;
use Symfony\Component\Console\Application;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\CacheSizingAdvisor;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Cron\Service\CronTokenProvider;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFRequestValidator;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Security\CSRF\RequestOriginValidator;
use TotalCMS\Domain\Security\Request\ClientIpResolver;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Admin\Nav\AdminNavRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\EditionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\BuilderTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\BuilderAssetRenderer;
use TotalCMS\Domain\Twig\Service\BuilderNavigation;
use TotalCMS\Domain\Twig\Service\BuilderTemplateRenderer;
use TotalCMS\Domain\Twig\Service\CloneDialogRenderer;
use TotalCMS\Domain\Twig\Service\DashboardRenderer;
use TotalCMS\Domain\Twig\Service\DepotBrowserRenderer;
use TotalCMS\Domain\Twig\Service\GalleryRenderer;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\ImageRenderer;
use TotalCMS\Domain\Twig\Service\JobQueueRenderer;
use TotalCMS\Domain\Twig\Service\LoadMoreRenderer;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Slim\Test\TestResponse;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

$_SERVER['APP_ENV'] = 'test';

require_once __DIR__ . '/worker-paths.php';

// Ensure Symfony Console Resources directory exists (missing in some CI environments)
$consoleResourcesDir = dirname((new ReflectionClass(Application::class))->getFileName()) . '/Resources';
if (!is_dir($consoleResourcesDir)) {
	@mkdir($consoleResourcesDir, 0755, true);
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function bootstrap()
{
	// Tests reset state by wiping the data dir (recursiveDelete) and rebuilding
	// the app, but APCu lives in shared memory for the WHOLE php process and is
	// not touched by a filesystem wipe. When APCu is enabled (the test scripts
	// pass `-d apc.enable_cli=1`, making it CacheManager's L1 backend) that lets
	// cached collections/objects/indexes leak across tests — causing
	// order-dependent, flaky failures ("object already exists", X-Total 0, etc.).
	// Clearing it on every app boot makes the reset complete so each test starts
	// from a clean cache. No-op when APCu isn't loaded.
	if (function_exists('apcu_clear_cache')) {
		apcu_clear_cache();
	}

	// Session state leaks the same way: after a request the middleware
	// write-closes the session, so session_status() is NONE and the
	// "session_destroy() if active" idiom in test files never fires — the
	// session FILE survives, and because the process keeps the same session id,
	// the next request's session_start() resumes it and resurrects auth keys
	// written by an earlier test file (e.g. the OAuth feature tests logging in
	// as admin) into the next file's "unauthenticated" requests. Destroy any
	// active session, clear the superglobal, and reset the session id so a
	// stale file can never be resumed.
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$_SESSION = [];
	session_id('');

	return require __DIR__ . '/../config/bootstrap.php';
}

function testDataDir(): string
{
	return __DIR__ . '/test-data/';
}

function testData(string $file): string
{
	return __DIR__ . '/test-data/' . $file;
}

function cmsDataDir(): string
{
	return tcmsTestDataDir() . '/';
}

function templatePath(string $id, ?string $folder = null): string
{
	$basePath = cmsDataDir() . 'builder/';
	if ($folder !== null && $folder !== '') {
		$basePath .= $folder . '/';
	}

	return $basePath . $id . '.twig';
}

function designerMetaPath(string $id, ?string $folder = null): string
{
	$basePath = cmsDataDir() . 'builder/';
	if ($folder !== null && $folder !== '') {
		$basePath .= $folder . '/';
	}

	return $basePath . $id . '.designer.json';
}

function collectionPath(string $collection): string
{
	return cmsDataDir() . "$collection/";
}

function metaPath(string $collection): string
{
	return cmsDataDir() . "$collection/.meta.json";
}

function schemaPath(string $id): string
{
	return cmsDataDir() . ".schemas/$id.json";
}

function reservedSchemaPath(): string
{
	return __DIR__ . '/../resources/schemas/';
}

function reservedTemplatePath(): string
{
	return __DIR__ . '/../resources/templates/';
}

function jumpstartResourcePath(string $file = ''): string
{
	return __DIR__ . '/../resources/jumpstart/' . $file;
}

function indexPath(string $collection): string
{
	return cmsDataDir() . "$collection/.index.json";
}

function objectPath(string $collection, string $id): string
{
	return cmsDataDir() . "$collection/$id.json";
}

function objectFilesPath(string $collection, string $id): string
{
	return cmsDataDir() . "$collection/$id";
}

function recursiveDelete(string $dir, array $preserve = [], bool $forceComplete = false)
{
	$isRootDataDir = rtrim($dir, '/') === rtrim(cmsDataDir(), '/');

	// Wipe pass — only if the directory actually exists. A non-existent root
	// data dir on a fresh clone (CI, new checkout) still needs the fixture
	// restore below, so we don't short-circuit here.
	if (file_exists($dir)) {
		if (!is_dir($dir)) {
			return unlink($dir);
		}

		foreach (scandir($dir) as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			// Skip explicitly-preserved entries (caller-supplied opt-in only).
			if (in_array($item, $preserve, true)) {
				continue;
			}

			if (!recursiveDelete($dir . DIRECTORY_SEPARATOR . $item, [], $forceComplete)) {
				return false;
			}
		}

		// Don't remove the root tcms-data dir itself — only its contents.
		if (!$isRootDataDir) {
			return rmdir($dir);
		}
	}

	// Restore checked-in fixtures (auth users, .system/access-groups.json,
	// etc.) so tests start from a known state every time. Fixtures live at
	// /tests/tcms-data-fixtures/ as the source of truth; the whole
	// /tests/tcms-data/ tree is gitignored. Runs even when the root dir
	// didn't exist beforehand — recursiveCopy() creates it.
	// $forceComplete suppresses the restore — used when a test genuinely
	// needs a pre-fixture state (e.g. setup-wizard tests).
	if ($isRootDataDir && !$forceComplete) {
		restoreFixtures();
	}

	return true;
}

/**
 * Whether $dir's filesystem reflects an explicit chmod(0600) back through
 * fileperms(). Some CI filesystems carry a default POSIX ACL, so the group
 * bits in st_mode show the ACL mask (0644) even after chmod(0600) — meaning a
 * 0600 assertion is unverifiable there, not that the code failed to chmod.
 * Permission tests probe the directory they actually write to and skip the
 * octal assertion when it returns false, so they still catch a real
 * regression (chmod works in the dir, but the code didn't apply it).
 */
function chmodReflectsPrivateMode(string $dir): bool
{
	$probe = $dir . '/.tcms-chmod-probe-' . uniqid();
	if (@file_put_contents($probe, 'x') === false) {
		return false;
	}

	@chmod($probe, 0600);
	clearstatcache(true, $probe);
	$reflects = substr(sprintf('%o', fileperms($probe)), -4) === '0600';
	@unlink($probe);

	return $reflects;
}

/**
 * Copy every file under /tests/tcms-data-fixtures/ into the live test data
 * dir. Called by recursiveDelete() at root-dir cleanup so every test run
 * starts with the canonical fixtures (auth users, access-groups.json, etc.)
 * in place. Idempotent — safe to call standalone if you need to restore
 * fixtures without first wiping the dir.
 */
/**
 * Sign in as a fixture user with authentication switched ON.
 *
 * The suite runs with `auth.enable = false` (config/local.test.php) so ~9,900
 * tests don't each need a session. The side effect is that EVERY access
 * middleware returns early — BaseAccessMiddleware::process() bails on
 * `!authEnabled()` before it ever reaches checkPermission(). A feature test
 * that means to assert authorization therefore asserts nothing unless it turns
 * auth on first, which is how the self-profile carve-out stayed broken from
 * 3.1.0 to 3.5.2 with a green suite the whole way.
 *
 * Safe to mutate: Config::init() returns a fresh object, the container caches
 * one per container, and each test builds its own app via
 * setUpApp(bootstrap()) — so this cannot leak into another test.
 *
 * `$authCollection` defaults to '' on purpose. That is what a real sign-in at
 * the plain `/admin/login` route stores (it has no `{collection}` segment), so
 * it is the realistic case, not an edge one.
 */
/**
 * Assemble a RenderTwigAdapter from the collaborators the OLD monolithic
 * constructor took, so the unit tests that mock those collaborators keep
 * their shape after the adapter was split into per-concern renderers
 * (LoadMore / Image / Gallery / CloneDialog). Same positional order and
 * parameter names as that constructor had, so call sites only swap `new`.
 */
function buildRenderTwigAdapter(
	HtmxRenderer $htmxRenderer,
	Config $config,
	DataTwigAdapter $data,
	MediaTwigAdapter $media,
	CollectionFetcher $collectionFetcher,
	CollectionLister $collectionLister,
	SchemaFetcher $schemaFetcher,
	GridRenderer $grid,
	LoggerFactory $loggerFactory,
	?DepotBrowserRenderer $depotBrowserRenderer = null,
	?IndexQueryService $indexQueryService = null,
	?Closure $dataViewQueryServiceFactory = null,
	?Closure $twigEngineFactory = null,
): RenderTwigAdapter {
	return new RenderTwigAdapter(
		$data,
		$media,
		$grid,
		new LoadMoreRenderer($htmxRenderer, $config, $indexQueryService, $dataViewQueryServiceFactory, $twigEngineFactory),
		new ImageRenderer($media, $data),
		new GalleryRenderer($media, $data, $config, $loggerFactory),
		new CloneDialogRenderer($config, $collectionFetcher, $schemaFetcher, $collectionLister),
		$depotBrowserRenderer ?? new DepotBrowserRenderer(),
	);
}

/**
 * Assemble an AdminTwigAdapter from the collaborators the OLD monolithic
 * constructor took, so unit tests that mock those collaborators keep their
 * shape after the adapter was split into DashboardRenderer /
 * JobQueueRenderer / BuilderTemplateRenderer. Same positional order and
 * parameter names as that constructor had, so call sites only swap `new`.
 */
function buildAdminTwigAdapter(
	Config $config,
	AuthTwigAdapter $auth,
	CollectionLister $collectionLister,
	SchemaLister $schemaLister,
	TemplateLister $templateLister,
	JobManager $jobManager,
	DevModeManager $devModeManager,
	CollectionEditionService $collectionEditionService,
	CacheReporter $cacheReporter,
	LicenseStatus $licenseStatus,
	IndexReader $indexReader,
	ServerChecker $checker,
	LogAnalyzer $logAnalyzer,
	ImageCacheService $imageCacheService,
	CacheSizingAdvisor $cacheSizingAdvisor,
	UpdateChecker $updateChecker,
	BuilderConfigService $builderConfig,
	CollectionFetcher $collectionFetcher,
	BuilderTemplatePaths $paths,
	JobQueueHealth $jobQueueHealth,
	TranslationService $translator,
	EditionFeatureService $editionFeatures,
	AutomationLoader $automationLoader,
	AutomationRunReader $automationRunReader,
	ExtensionStateRepository $extensionStateRepository,
	CronTokenProvider $cronTokens,
	?AdminNavRegistry $nav = null,
): AdminTwigAdapter {
	return new AdminTwigAdapter(
		$config,
		$devModeManager,
		$collectionEditionService,
		$cacheReporter,
		$licenseStatus,
		$checker,
		$logAnalyzer,
		$imageCacheService,
		$cacheSizingAdvisor,
		$translator,
		$editionFeatures,
		new DashboardRenderer($config, $auth, $collectionLister, $schemaLister, $templateLister, $jobManager, $cacheReporter, $licenseStatus, $indexReader, $updateChecker, $jobQueueHealth, $editionFeatures, $automationLoader, $automationRunReader, $extensionStateRepository),
		new JobQueueRenderer($config, $jobManager, $cronTokens),
		new BuilderTemplateRenderer($templateLister, $paths, $builderConfig, $indexReader, $collectionFetcher),
		// The adapter tests never render the rail, so a bare ExtensionManager
		// (no constructor run) is enough to satisfy the registry's dependency.
		$nav ?? new AdminNavRegistry($auth, new EditionTwigAdapter($editionFeatures), $translator, $config, (new ReflectionClass(ExtensionManager::class))->newInstanceWithoutConstructor()),
	);
}

/**
 * Assemble a BuilderTwigAdapter from the collaborators the OLD constructor
 * took (navigation and assets are now their own services).
 */
function buildBuilderTwigAdapter(
	BuilderConfigService $builderConfig,
	IndexReader $indexReader,
	BuilderOrderService $orderService,
	Config $config,
): BuilderTwigAdapter {
	return new BuilderTwigAdapter(
		$builderConfig,
		$indexReader,
		$config,
		new BuilderNavigation($builderConfig, $indexReader, $orderService),
		new BuilderAssetRenderer($config),
	);
}

function signInAs(App $app, string $userId, string $authCollection = ''): void
{
	/** @var Config $config */
	$config         = $app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;

	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, $userId);
	$session->set(SessionKeys::AUTH_COLLECTION, $authCollection);

	// A session-authenticated API write must also carry the CSRF token — the
	// browser sends it from TotalForm / the admin meta tag. Mint one into the
	// same session and register it as a default header so the test exercises
	// the authorization layer rather than stopping at CSRF.
	/** @var CSRFTokenManager $csrf */
	$csrf = $app->getContainer()->get(CSRFTokenManager::class);
	TotalCMS\Slim\Pest\withHeader('X-CSRF-Token', $csrf->getToken());
}

function restoreFixtures(): void
{
	$src = __DIR__ . '/tcms-data-fixtures';
	if (!is_dir($src)) {
		return;
	}
	recursiveCopy($src, rtrim(cmsDataDir(), '/'));
}

/**
 * Build a real, dev-environment ExtensionGuard for use in tests.
 *
 * Env is 'dev' so auto-quarantine never fires (quarantine is destructive and
 * prod-only). Collaborators are real (not PHPUnit mocks) so this helper works
 * from BOTH Pest closures AND class-based PHPUnit TestCases — test() resolves
 * differently in the two contexts, and createMock() outside a TestCase trips
 * Pest into treating the call as a test description.
 *
 * On the guard's success path it touches nothing; in dev a throw only logs
 * (NullLogger) and bumps the in-memory counter. This lets ExtensionManager
 * tests pass a genuine guard with a single inserted constructor argument.
 */
function testExtensionGuard(): ExtensionGuard
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = 'dev';
	$env         = new EnvironmentResolver($config, false);

	// In-memory CacheManager subclass — overrides the two methods the guard's
	// failure counter uses and skips the heavy 11-dependency parent constructor.
	$cache = new class extends CacheManager {
		/** @var array<string,mixed> */
		private array $store = [];

		public function __construct()
		{
			// Intentionally bypass parent — the guard never touches the cache
			// services, only getData()/storeData().
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function getOperationalData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			$this->store[$key] = $data;

			return true;
		}
	};

	// Real state repo over a shared temp dir (no PHPUnit mock needed).
	// A single directory is created once and reused across all calls; a
	// shutdown function removes it when the test process exits so it does
	// not accumulate in the system temp directory.
	static $tmpRoot = null;
	if ($tmpRoot === null) {
		$tmpRoot = sys_get_temp_dir() . '/tcms-test-guard-' . bin2hex(random_bytes(6));
		@mkdir($tmpRoot, 0777, true);
		register_shutdown_function(function () use ($tmpRoot): void {
			if (is_dir($tmpRoot)) {
				recursiveDelete($tmpRoot, forceComplete: true);
			}
		});
	}
	$flysystem = new Filesystem(new LocalFilesystemAdapter($tmpRoot));
	$storage   = new StorageFilesystemAdapter($flysystem);
	$repo      = new ExtensionStateRepository($storage);

	return new ExtensionGuard(
		$env,
		$cache,
		$repo,
		new NullLogger(),
		testExtensionProfiler(),
	);
}

/**
 * Build a real, dev-environment ExtensionProfiler for use in tests.
 *
 * Env is 'dev' so shouldSurfaceErrors() is true and the profiler always
 * profiles (no sampling), giving deterministic timing in tests. Collaborators
 * are real (not PHPUnit mocks) so this works from both Pest closures and
 * class-based TestCases — mirroring testExtensionGuard().
 */
function testExtensionProfiler(): ExtensionProfiler
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = 'dev';
	$env         = new EnvironmentResolver($config, false);

	$cache = new class extends CacheManager {
		/** @var array<string,mixed> */
		private array $store = [];

		public function __construct()
		{
			// Intentionally bypass parent — the profiler only uses getData()/storeData().
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function getOperationalData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			$this->store[$key] = $data;

			return true;
		}
	};

	return new ExtensionProfiler(
		$env,
		$cache,
		1,
		new NullLogger(),
	);
}

/**
 * Copy a directory tree from $src to $dst, creating dirs as needed and
 * overwriting existing files. Helper for restoreFixtures().
 */
function recursiveCopy(string $src, string $dst): void
{
	if (!is_dir($dst)) {
		mkdir($dst, 0777, true);
	}
	foreach (scandir($src) as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$srcPath = $src . DIRECTORY_SEPARATOR . $item;
		$dstPath = $dst . DIRECTORY_SEPARATOR . $item;
		if (is_dir($srcPath)) {
			recursiveCopy($srcPath, $dstPath);
		} else {
			copy($srcPath, $dstPath);
		}
	}
}

/**
 * A ClientIpResolver for tests, with the proxy-header trust mode of your choice.
 *
 * 'auto' (the shipped default) honours CF-Connecting-IP / X-Forwarded-For only
 * when the request arrives from a private or loopback address, so a test that
 * wants those headers honoured must either present a private REMOTE_ADDR or ask
 * for 'always'.
 */
function testClientIpResolver(
	string $trustProxyHeaders = ClientIpResolver::TRUST_AUTO,
): ClientIpResolver {
	$config                    = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->trustProxyHeaders = $trustProxyHeaders;

	return new ClientIpResolver($config);
}

/**
 * A private data dir for one test, safe to use under --parallel.
 *
 * Test files used to build these as `sys_get_temp_dir() . '/prefix-' . uniqid()`.
 * uniqid() is derived from the clock (seconds + microseconds) with no
 * per-process entropy, so two workers entering setUp() in the same microsecond
 * get the SAME directory. Measured across 8 concurrent processes, 16000
 * uniqid() calls produced 3928 distinct values. When it collided, one file's
 * setUp()/tearDown() deleted state another was mid-test on.
 *
 * The worker token makes the path unique across processes and random_bytes()
 * makes it unique within one, so neither half depends on the clock. The
 * directory is removed at shutdown if the test left it empty.
 */
function tcmsTestTempDir(string $prefix): string
{
	try {
		$suffix = bin2hex(random_bytes(8));
	} catch (RandomException) {
		$suffix = bin2hex((string)getmypid()) . bin2hex((string)mt_rand());
	}

	$token = tcmsTestWorkerToken();
	$token = $token === '' ? 'solo' : $token;

	$dir = sys_get_temp_dir() . '/' . $prefix . '-' . $token . '-' . getmypid() . '-' . $suffix;

	// Nothing used to remove these: this machine had 7280 leftovers from past
	// runs. Clean up our own at shutdown, matched by the prefix we wrote.
	static $dirs = [];
	if ($dirs === []) {
		register_shutdown_function(static function () use (&$dirs): void {
			foreach ($dirs as $created) {
				foreach ((array)@scandir($created . '/.system') as $entry) {
					if ($entry !== '.' && $entry !== '..') {
						@unlink($created . '/.system/' . $entry);
					}
				}
				@rmdir($created . '/.system');
				@rmdir($created); // left alone if the test put anything else in it
			}
		});
	}
	$dirs[] = $dir;

	return $dir;
}

/** A private data dir for one dev-mode test. See tcmsTestTempDir(). */
function devModeDataDir(): string
{
	return tcmsTestTempDir('tcms-devmode');
}

/**
 * Build a DevModeManager rooted at the given data dir. The state file lives at
 * `<datadir>/.system/totalcms_devmode.json` — per-install, never the shared
 * global /tmp path (which collides across tenants on shared hosting). Pass the
 * same $datadir to two calls to exercise shared-file state across managers.
 */
function devModeManager(string $datadir): DevModeManager
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $datadir;

	if (!is_dir($config->systemDir())) {
		@mkdir($config->systemDir(), 0775, true);
	}

	return new DevModeManager(
		new EventDispatcher(new NullLogger()),
		$config,
	);
}

/** The per-install dev-mode state file path for a given data dir. */
function devModeFile(string $datadir): string
{
	return $datadir . '/.system/totalcms_devmode.json';
}

/**
 * Build a CSRFRequestValidator wired to a real origin validator.
 *
 * The CSRF policy is "same origin OR valid token", so every consumer needs both
 * halves. Requests without Origin/Referer land on the token path, which is what
 * the token-focused suites exercise; pass a $domain matching the request's
 * Origin to exercise the same-origin path instead.
 */
function csrfValidatorFor(
	CSRFTokenManager $manager,
	string $domain = 'tests.local',
): CSRFRequestValidator {
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = $domain;

	return new CSRFRequestValidator(
		$manager,
		new RequestOriginValidator($config),
	);
}

/**
 * Mock HTTP client returning a fixed response.
 *
 * Lives here rather than in a test file because two suites need it
 * (LicenseValidatorHttpTest and TemplateDesignerSyncTest). A global
 * function declared inside one *Test.php file is only visible to another
 * when both happen to load into the same process — true for a serial run,
 * false under `pest --parallel`, where the two files can land in different
 * workers and the call fails with "undefined function".
 */
function createMockHttpClient(
	HttpResponse $response,
): HttpClientInterface {
	$client = test()->createMock(HttpClientInterface::class);
	$client->method('request')->willReturn($response);

	return $client;
}

/**
 * Drain a streamed (CallbackStream) response body and return what it wrote.
 *
 * A streamed body echoes its frames rather than returning them, so the content
 * lands in an output buffer and has to be captured from there. Two buffers are
 * nested because the two streams T3 serves flush differently:
 *
 *   - T3's own GET listening stream calls `@ob_flush()`, which pushes its
 *     content from the inner buffer out to the enclosing one.
 *   - The SDK's `subscriptions/listen` stream calls plain `flush()`, which
 *     targets the SAPI and leaves the content sitting in the inner buffer.
 *
 * So neither buffer alone is reliable — the earlier single-capture version read
 * empty for SDK streams because it discarded the inner buffer the content was
 * still in. Both are captured and concatenated.
 *
 * Lives here rather than in a test file because both streams need it, and a
 * global declared inside one *Test.php is invisible to another under
 * `pest --parallel` where the two files can land in different workers.
 */
function drainStreamedBody(ResponseInterface|TestResponse $response): string
{
	ob_start();
	ob_start();
	$response->getBody()->__toString();
	$inner = (string)ob_get_clean();
	$outer = (string)ob_get_clean();

	return $inner . $outer;
}
