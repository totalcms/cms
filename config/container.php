<?php

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Mcp\JsonRpc\MessageFactory as McpMessageFactory;
use Mcp\Server\Protocol as McpProtocol;
use Mcp\Server\Session\FileSessionStore as McpFileSessionStore;
use Mcp\Server\Session\SessionManager as McpSessionManager;
use Mcp\Server\Session\SessionStoreInterface as McpSessionStoreInterface;
use Monolog\Level;
use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\Middleware\SessionStartMiddleware;
use Odan\Session\PhpSession;
use Odan\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Selective\Validation\Encoder\JsonEncoder;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Selective\Validation\Transformer\ErrorDetailsResultTransformer;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteParserInterface;
use Slim\Middleware\ErrorMiddleware;
use Slim\Views\PhpRenderer;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Automation\Service\AutomationEventSubscriber;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewDependencyResolver;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Listener\CacheInvalidationListener;
use TotalCMS\Domain\Event\Listener\CollectionMetadataListener;
use TotalCMS\Domain\Event\Listener\DataViewListener;
use TotalCMS\Domain\Event\Listener\DeckFileCleanupListener;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Event\Listener\McpResourceSubscriptionListener;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptDiscoveryService;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRegistrar;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRenderer;
use TotalCMS\Domain\Mcp\Resource\Service\CollectionResourceRegistrar;
use TotalCMS\Domain\Mcp\Resource\Service\DataViewResourceRegistrar;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Subscription\Service\McpNotificationService;
use TotalCMS\Domain\Mcp\Subscription\Service\McpSubscriptionManager;
use TotalCMS\Domain\Mcp\Subscription\Service\ResourceNotifier;
use TotalCMS\Domain\Mcp\Subscription\Service\SubscriptionIndex;
use TotalCMS\Domain\Mcp\Tool\Admin\CacheTools;
use TotalCMS\Domain\Mcp\Tool\Admin\CollectionTools;
use TotalCMS\Domain\Mcp\Tool\Admin\ExtensionTools;
use TotalCMS\Domain\Mcp\Tool\Admin\ObjectTools;
use TotalCMS\Domain\Mcp\Tool\Admin\SchemaTools;
use TotalCMS\Domain\Mcp\Tool\Admin\SiteInfoTool;
use TotalCMS\Domain\Mcp\Tool\Content\GetObjectTool;
use TotalCMS\Domain\Mcp\Tool\Content\GetResourceTool;
use TotalCMS\Domain\Mcp\Tool\Content\GetViewTool;
use TotalCMS\Domain\Mcp\Tool\Content\QueryCollectionTool;
use TotalCMS\Domain\Mcp\Tool\Content\QueryViewTool;
use TotalCMS\Domain\Mcp\Tool\Content\SearchCollectionsTool;
use TotalCMS\Domain\Mcp\Tool\Content\SearchCollectionTool;
use TotalCMS\Domain\Mcp\Tool\Discovery\DescribeCollectionTool;
use TotalCMS\Domain\Mcp\Tool\Discovery\DescribeViewTool;
use TotalCMS\Domain\Mcp\Tool\Discovery\ListCollectionsTool;
use TotalCMS\Domain\Mcp\Tool\Discovery\ListViewsTool;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;
use TotalCMS\Domain\Mcp\Tool\Service\SavedQueryToolFactory;
use TotalCMS\Domain\Mcp\Tool\Service\SchemaToolRegistrar;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Migration\Migration\EnsureAutomationsCollectionMigration;
use TotalCMS\Domain\Migration\Migration\EnsureMcpPromptCollectionMigration;
use TotalCMS\Domain\Migration\Migration\LegacyTemplatesMigration;
use TotalCMS\Domain\Migration\Repository\MigrationStateRepository;
use TotalCMS\Domain\Migration\Service\MigrationRunner;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthReplayDetector;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthServerFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyDataProcessor;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Search\Listener\ContentChangeListener;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Domain\Search\Service\SearchService;
use TotalCMS\Domain\Search\Service\SearchServiceInterface;
use TotalCMS\Domain\Search\Service\TextSearchProvider;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\BuilderTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\EditionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\LocaleTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\SchemaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\UtilsTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\ViewTwigAdapter;
use TotalCMS\Domain\Twig\Service\DepotBrowserRenderer;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Handler\DefaultErrorHandler;
use TotalCMS\Middleware\BasePathMiddleware;
use TotalCMS\Middleware\Development\SentryMiddleware;
use TotalCMS\Middleware\Response\PreviewRouteMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\GuzzleHttpClient;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\PathResolver;

return [
	// Application settings — plain closure (rather than `Config::init(...)`)
	// so the entire container is compileable; PHP-DI's compiler rejects
	// first-class callables because they internally reference `self`.
	Config::class => fn (): Config => Config::init(),

	App::class => function (ContainerInterface $container): App {
		AppFactory::setContainer($container);

		return AppFactory::create();
	},

	SessionStartMiddleware::class => fn (ContainerInterface $container): SessionStartMiddleware => new SessionStartMiddleware($container->get(PhpSession::class)),

	PhpSession::class => function (ContainerInterface $container): PhpSession {
		$sessionConfig = $container->get(Config::class)->session;

		// Skip session configuration in CLI mode (no HTTP context)
		if (PHP_SAPI === 'cli') {
			return new PhpSession();
		}

		// Ensure session directory exists
		if (isset($sessionConfig['save_path']) && !is_dir($sessionConfig['save_path'])) {
			@mkdir($sessionConfig['save_path'], 0755, true);
		}

		// CRITICAL: Set cache_limiter BEFORE any other session configuration
		// This prevents PHP from sending no-cache headers automatically
		if (isset($sessionConfig['cache_limiter'])) {
			session_cache_limiter($sessionConfig['cache_limiter']);
		}

		// Force session settings to prevent hosting provider overrides
		if (isset($sessionConfig['name'])) {
			ini_set('session.name', $sessionConfig['name']);
		}
		if (isset($sessionConfig['save_path'])) {
			ini_set('session.save_path', $sessionConfig['save_path']);
		}
		if (isset($sessionConfig['cookie_domain'])) {
			ini_set('session.cookie_domain', $sessionConfig['cookie_domain']);
		}
		if (isset($sessionConfig['cookie_path'])) {
			ini_set('session.cookie_path', $sessionConfig['cookie_path']);
		}
		if (isset($sessionConfig['gc_maxlifetime'])) {
			ini_set('session.gc_maxlifetime', (string)$sessionConfig['gc_maxlifetime']);
		}

		// Set the odan/session 'lifetime' option to match cookie_lifetime.
		// Without this, odan/session defaults to 7200s (2 hours) for the session
		// cookie, causing premature session expiry regardless of gc_maxlifetime.
		if (isset($sessionConfig['cookie_lifetime']) && !isset($sessionConfig['lifetime'])) {
			$sessionConfig['lifetime'] = (int)$sessionConfig['cookie_lifetime'];
		}

		return new PhpSession($sessionConfig);
	},

	// Bind SessionInterface to PhpSession for dependency injection
	SessionInterface::class => fn (ContainerInterface $container) => $container->get(PhpSession::class),

	ResponseFactoryInterface::class => fn (ContainerInterface $container) => $container->get(App::class)->getResponseFactory(),

	ServerRequestFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

	StreamFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

	UploadedFileFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

	UriFactoryInterface::class => fn (ContainerInterface $container) => $container->get(Psr17Factory::class),

	RouteParserInterface::class => fn (ContainerInterface $container) => $container->get(App::class)->getRouteCollector()->getRouteParser(),

	// The logger factory.
	// The admin-controlled appLogLevel overrides the default level baked into
	// $config['logger']['level'] so channelLogger() callers pick up the
	// user's choice without being individually rewired.
	LoggerFactory::class => function (ContainerInterface $container): LoggerFactory {
		$config            = $container->get(Config::class);
		$settings          = $config->logger;
		$fallback          = $settings['level'] instanceof Level ? $settings['level'] : Level::Info;
		$settings['level'] = LoggerFactory::resolveLevel($config->appLogLevel, $fallback);

		return new LoggerFactory($settings);
	},

	// The data dir iterator factory
	StorageFilesystemAdapter::class => function (ContainerInterface $container): StorageFilesystemAdapter {
		$rootPath = $container->get(Config::class)->datadir;

		// Note: LocalFilesystemAdapter may create the directory on first write operation
		// but not on instantiation. The setup wizard is responsible for creating the directory.
		$filesystem = new Filesystem(new LocalFilesystemAdapter($rootPath));

		return new StorageFilesystemAdapter($filesystem);
	},

	StorageAdapterInterface::class => fn (ContainerInterface $container) => $container->get(StorageFilesystemAdapter::class),

	// Explicit definition (not autowired) so the datadir can be passed for
	// enforcing 0600 on settings files, which may hold extension secrets.
	ExtensionSettingsManager::class => function (ContainerInterface $container): ExtensionSettingsManager {
		return new ExtensionSettingsManager(
			$container->get(StorageFilesystemAdapter::class),
			(string) $container->get(Config::class)->datadir,
		);
	},

	BasePathMiddleware::class => function (ContainerInterface $container): BasePathMiddleware {
		$app = $container->get(App::class);

		return new BasePathMiddleware($app);
	},

	ValidationExceptionMiddleware::class => function (ContainerInterface $container): ValidationExceptionMiddleware {
		$factory = $container->get(ResponseFactoryInterface::class);

		return new ValidationExceptionMiddleware($factory, new ErrorDetailsResultTransformer(), new JsonEncoder());
	},

	PreviewRouteMiddleware::class => function (ContainerInterface $container): PreviewRouteMiddleware {
		$api = $container->get(Config::class)->api;

		return new PreviewRouteMiddleware($api);
	},

	SentryMiddleware::class => function (ContainerInterface $container): SentryMiddleware {
		$enabled = $container->get(Config::class)->sentry;

		return new SentryMiddleware($enabled);
	},

	ErrorMiddleware::class => function (ContainerInterface $container): ErrorMiddleware {
		$app = $container->get(App::class);

		$config = (array)$container->get(Config::class)->logger;
		$logger = $container->get(LoggerFactory::class)->addFileHandler(
			filename: $config['filename'],
			maxFiles: $config['maxFiles'],
			permissions: $config['permissions'],
			level: $config['level'],
		)->createLogger($config['name']);

		$config          = (array)$container->get(Config::class)->error;
		$errorMiddleware = new ErrorMiddleware(
			$app->getCallableResolver(),
			$app->getResponseFactory(),
			(bool)$config['display_error_details'],
			(bool)$config['log_errors'],
			(bool)$config['log_error_details'],
			$logger
		);

		$errorMiddleware->setDefaultErrorHandler($container->get(DefaultErrorHandler::class));

		return $errorMiddleware;
	},

	DefaultErrorHandler::class => fn (ContainerInterface $container): DefaultErrorHandler => new DefaultErrorHandler(
		$container->get(JsonRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(LoggerFactory::class),
		$container->get(OPcacheService::class)
	),

	PhpRenderer::class => fn (ContainerInterface $container): PhpRenderer => new PhpRenderer($container->get(Config::class)->template),

	PropertyDataProcessorInterface::class => fn (ContainerInterface $container): PropertyDataProcessor => new PropertyDataProcessor(),

	PropertyDataProcessor::class => fn (ContainerInterface $container) => $container->get(PropertyDataProcessorInterface::class),

	RenderTwigAdapter::class => fn (ContainerInterface $container): RenderTwigAdapter => new RenderTwigAdapter(
		$container->get(HtmxRenderer::class),
		$container->get(Config::class),
		$container->get(DataTwigAdapter::class),
		$container->get(MediaTwigAdapter::class),
		$container->get(CollectionFetcher::class),
		$container->get(CollectionLister::class),
		$container->get(SchemaFetcher::class),
		$container->get(GridRenderer::class),
		$container->get(LoggerFactory::class),
		$container->get(DepotBrowserRenderer::class),
		$container->get(IndexQueryService::class),
		fn () => $container->get(DataViewQueryService::class),
		fn () => $container->get(TwigEngine::class),
	),

	TranslationService::class => fn (ContainerInterface $container): TranslationService => new TranslationService(
		$container->get(Config::class),
		PathResolver::packageRoot() . '/resources/translations',
	),

	TotalCMSTwigAdapter::class => fn (ContainerInterface $container): TotalCMSTwigAdapter => new TotalCMSTwigAdapter(
		$container->get(Config::class),
		$container->get(TotalFormFactory::class),
		$container->get(LicenseStatus::class),
		$container->get(EditionTwigAdapter::class),
		$container->get(LoggerFactory::class),
		$container->get(RenderTwigAdapter::class),
		$container->get(ViewTwigAdapter::class),
		$container->get(SchemaTwigAdapter::class),
		$container->get(AuthTwigAdapter::class),
		$container->get(DataTwigAdapter::class),
		$container->get(MediaTwigAdapter::class),
		$container->get(CollectionTwigAdapter::class),
		$container->get(AdminTwigAdapter::class),
		$container->get(BuilderTwigAdapter::class),
		new LocaleTwigAdapter($container->get(TranslationService::class), $container->get(Config::class)),
		new UtilsTwigAdapter(),
	),

	// HttpClientInterface → GuzzleHttpClient. Interface binding (autowiring can't
	// resolve interfaces without an explicit mapping). All other middleware,
	// services, and edition gates above (auth, access control, license,
	// editions, cache, data views, twig, etc.) are autowired — their
	// constructors take only typed class dependencies that PHP-DI resolves.
	HttpClientInterface::class => fn (): HttpClientInterface => new GuzzleHttpClient(),

	JumpStartExporter::class => fn (ContainerInterface $container): JumpStartExporter => new JumpStartExporter(
		$container->get(CollectionLister::class),
		$container->get(SchemaLister::class),
		$container->get(SchemaFetcher::class),
		$container->get(ObjectFetcher::class),
		$container->get(IndexReader::class),
		$container->get(TemplateLister::class),
		$container->get(TemplateFetcher::class),
		new JumpStartData(),
		$container->get(CacheManager::class),
		$container->get(LoggerFactory::class),
	),

	EventDispatcher::class => function (ContainerInterface $container): EventDispatcher {
		$extLevel   = LoggerFactory::resolveLevel((string)($container->get(Config::class)->extensions['logLevel'] ?? 'info'), Level::Info);
		$dispatcher = new EventDispatcher(
			$container->get(LoggerFactory::class)->channelLogger(LogChannel::Events, $extLevel),
		);

		// Register internal listeners with lazy resolution to avoid circular deps.
		// Listeners are resolved from the container on first event dispatch, not at registration time.
		$lazy = fn (string $class, string $method): Closure => function (array $payload) use ($container, $class, $method): void {
			$container->get($class)->$method($payload);
		};

		// CollectionMetadataListener (priority -100 = before extensions)
		$dispatcher->listen('object.created', $lazy(CollectionMetadataListener::class, 'onObjectCreated'), -100);
		$dispatcher->listen('object.updated', $lazy(CollectionMetadataListener::class, 'onObjectUpdated'), -100);
		$dispatcher->listen('object.deleted', $lazy(CollectionMetadataListener::class, 'onObjectDeleted'), -100);
		// Flush the collection count after a batch import. Priority -110 runs it
		// BEFORE IndexBuildListener's -100 rebuild so it can clear the in-memory
		// OID bump and write the true count before buildIndex reads the cache
		// (otherwise the bump leaks to disk and the count double-counts).
		$dispatcher->listen('import.completed', $lazy(CollectionMetadataListener::class, 'onImportCompleted'), -110);

		// McpSessionListener — drops MCP client sessions when the published
		// tool surface may have changed (per-property mcp.expose, collection
		// mcp.access toggle, new/removed collections). Clients auto-reconnect
		// and pick up the fresh surface on next request. mcp.* settings are
		// handled directly in SettingsSaver — no settings-save event yet.
		$dispatcher->listen('schema.saved', $lazy(TotalCMS\Domain\Mcp\Service\McpSessionListener::class, 'onToolSurfaceChange'), -100);
		$dispatcher->listen('collection.created', $lazy(TotalCMS\Domain\Mcp\Service\McpSessionListener::class, 'onToolSurfaceChange'), -100);
		$dispatcher->listen('collection.updated', $lazy(TotalCMS\Domain\Mcp\Service\McpSessionListener::class, 'onToolSurfaceChange'), -100);
		$dispatcher->listen('collection.deleted', $lazy(TotalCMS\Domain\Mcp\Service\McpSessionListener::class, 'onToolSurfaceChange'), -100);

		// IndexBuildListener
		$dispatcher->listen('object.created', $lazy(IndexBuildListener::class, 'onObjectCreated'), -100);
		$dispatcher->listen('object.updated', $lazy(IndexBuildListener::class, 'onObjectUpdated'), -100);
		$dispatcher->listen('object.deleted', $lazy(IndexBuildListener::class, 'onObjectDeleted'), -100);
		$dispatcher->listen('schema.saved', $lazy(IndexBuildListener::class, 'onSchemaSaved'), -100);
		$dispatcher->listen('import.completed', $lazy(IndexBuildListener::class, 'onImportCompleted'), -100);

		// DataViewListener
		$dispatcher->listen('object.created', $lazy(DataViewListener::class, 'onObjectChanged'), -100);
		$dispatcher->listen('object.updated', $lazy(DataViewListener::class, 'onObjectChanged'), -100);
		$dispatcher->listen('object.deleted', $lazy(DataViewListener::class, 'onObjectChanged'), -100);

		// DeckFileCleanupListener — diff-on-save deletion of orphaned deck-item uploads
		$dispatcher->listen('object.updated', $lazy(DeckFileCleanupListener::class, 'onObjectUpdated'), -100);

		// CacheInvalidationListener
		$dispatcher->listen('collection.created', $lazy(CacheInvalidationListener::class, 'onCollectionChanged'), -90);
		$dispatcher->listen('collection.updated', $lazy(CacheInvalidationListener::class, 'onCollectionChanged'), -90);
		$dispatcher->listen('collection.deleted', $lazy(CacheInvalidationListener::class, 'onCollectionChanged'), -90);
		$dispatcher->listen('import.completed', $lazy(CacheInvalidationListener::class, 'onCollectionChanged'), -90);
		$dispatcher->listen('schema.saved', $lazy(CacheInvalidationListener::class, 'onSchemaSaved'), -90);

		// McpResourceSubscriptionListener — fans out notifications/resources/updated
		// to MCP sessions subscribed to a collection's tcms:// URI. Listener
		// holds in-memory per-(uri, request) coalescing; EventDispatcher's
		// import suspension automatically suppresses object.* events during
		// JumpStart imports so we don't fire notification storms.
		// Subscriptions are gated by mcp.subscriptionsEnabled — the listener
		// still runs but McpServerFactory does not install our reverse-index
		// SubscriptionManagerInterface when the kill switch is off, so the
		// index stays empty and the notifier finds zero subscribers.
		$dispatcher->listen('object.created', $lazy(McpResourceSubscriptionListener::class, 'onObjectCreated'), -80);
		$dispatcher->listen('object.updated', $lazy(McpResourceSubscriptionListener::class, 'onObjectUpdated'), -80);
		$dispatcher->listen('object.deleted', $lazy(McpResourceSubscriptionListener::class, 'onObjectDeleted'), -80);

		// ReloadPulseListener — Builder live-reload
		$dispatcher->listen('template.saved', $lazy(TotalCMS\Domain\Builder\EventListener\ReloadPulseListener::class, 'onTemplateSaved'), -50);
		$dispatcher->listen('object.created', $lazy(TotalCMS\Domain\Builder\EventListener\ReloadPulseListener::class, 'onObjectChanged'), -50);
		$dispatcher->listen('object.updated', $lazy(TotalCMS\Domain\Builder\EventListener\ReloadPulseListener::class, 'onObjectChanged'), -50);
		$dispatcher->listen('devmode.disabled', $lazy(TotalCMS\Domain\Builder\EventListener\ReloadPulseListener::class, 'onDevModeDisabled'), -50);

		// ContentChangeListener — push content changes to the active search
		// provider's index/delete. Skips when active=text or indexOnSave=false;
		// failures enqueue search.reindex jobs for retry. Priority -50 so it
		// runs after the metadata/index listeners (-100) but before extension
		// listeners (default 0).
		$dispatcher->listen('object.created', $lazy(ContentChangeListener::class, 'onObjectSaved'), -50);
		$dispatcher->listen('object.updated', $lazy(ContentChangeListener::class, 'onObjectSaved'), -50);
		$dispatcher->listen('object.deleted', $lazy(ContentChangeListener::class, 'onObjectDeleted'), -50);

		// PromptChangeListener — invalidates the PromptDiscoveryService in-memory
		// cache whenever an mcp-prompt object changes so prompt edits go live
		// without a process restart. Priority -50 (same tier as ContentChangeListener).
		$dispatcher->listen('object.created', $lazy(TotalCMS\Domain\Mcp\Prompt\Handler\PromptChangeListener::class, 'onObjectChanged'), -50);
		$dispatcher->listen('object.updated', $lazy(TotalCMS\Domain\Mcp\Prompt\Handler\PromptChangeListener::class, 'onObjectChanged'), -50);
		$dispatcher->listen('object.deleted', $lazy(TotalCMS\Domain\Mcp\Prompt\Handler\PromptChangeListener::class, 'onObjectChanged'), -50);

		// AutomationEventSubscriber — fans every core event out to matching
		// event-trigger automations (enqueued async). Priority 100 = after all
		// core listeners, so index/cache are already updated when an automation
		// reads. Lazily resolved so a fresh automation is picked up per dispatch.
		foreach (CoreEvent::ALL as $automationEvent) {
			$dispatcher->listen(
				$automationEvent,
				static function (array $payload) use ($container, $automationEvent): void {
					$container->get(AutomationEventSubscriber::class)->handle($automationEvent, $payload);
				},
				100,
			);
		}

		return $dispatcher;
	},

	// -------------------------------------------------------------------------
	// Extensions
	// -------------------------------------------------------------------------

	ExtensionDiscovery::class => function (ContainerInterface $container): ExtensionDiscovery {
		$extLevel = LoggerFactory::resolveLevel((string)($container->get(Config::class)->extensions['logLevel'] ?? 'info'), Level::Info);

		return new ExtensionDiscovery(
			$container->get(Config::class),
			$container->get(ManifestValidator::class),
			$container->get(LoggerFactory::class)->channelLogger(LogChannel::Extensions, $extLevel),
		);
	},

	EnvironmentResolver::class => fn (ContainerInterface $container): EnvironmentResolver => new EnvironmentResolver(
		$container->get(Config::class),
		TotalCMS\TotalCMS::isPreview(),
	),

	ExtensionGuard::class => function (ContainerInterface $container): ExtensionGuard {
		$extLevel = LoggerFactory::resolveLevel((string)($container->get(Config::class)->extensions['logLevel'] ?? 'info'), Level::Info);

		return new ExtensionGuard(
			$container->get(EnvironmentResolver::class),
			$container->get(CacheManager::class),
			$container->get(ExtensionStateRepository::class),
			$container->get(LoggerFactory::class)->channelLogger(LogChannel::Extensions, $extLevel),
			$container->get(ExtensionProfiler::class),
		);
	},

	ExtensionProfiler::class => function (ContainerInterface $container): ExtensionProfiler {
		$config   = $container->get(Config::class);
		$extLevel = LoggerFactory::resolveLevel((string)($config->extensions['logLevel'] ?? 'info'), Level::Info);

		return new ExtensionProfiler(
			$container->get(EnvironmentResolver::class),
			$container->get(CacheManager::class),
			(int)($config->extensions['profileSampleRate'] ?? 50),
			$container->get(LoggerFactory::class)->channelLogger(LogChannel::Extensions, $extLevel),
		);
	},

	ExtensionManager::class => function (ContainerInterface $container): ExtensionManager {
		$extLevel = LoggerFactory::resolveLevel((string)($container->get(Config::class)->extensions['logLevel'] ?? 'info'), Level::Info);

		return new ExtensionManager(
			$container->get(ExtensionDiscovery::class),
			$container->get(ExtensionStateRepository::class),
			$container->get(ExtensionDependencySorter::class),
			$container->get(ExtensionSettingsManager::class),
			$container,
			$container->get(LoggerFactory::class)->channelLogger(LogChannel::Extensions, $extLevel),
			$container->get(ManifestValidator::class),
			$container->get(ExtensionGuard::class),
			$container->get(ExtensionProfiler::class),
		);
	},

	// Migrations — generic one-shot data/layout migrations. The runner has a
	// custom logger handler (migrations.log) and an array literal of registered
	// migrations, so the factory stays. The migrations themselves and the
	// state repo are autowired.
	MigrationRunner::class => fn (ContainerInterface $container): MigrationRunner => new MigrationRunner(
		[
			$container->get(LegacyTemplatesMigration::class),
			$container->get(EnsureMcpPromptCollectionMigration::class),
			$container->get(EnsureAutomationsCollectionMigration::class),
		],
		$container->get(MigrationStateRepository::class),
		$container->get(LoggerFactory::class)->channelLogger(LogChannel::Migrations),
	),

	// Per-page middleware infrastructure. Registry holds name → service-id
	// mappings; runner consumes the registry. Core middleware are registered
	// via the registry boot below; extensions register via ExtensionContext.
	TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry::class => function (ContainerInterface $container): TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry {
		$registry = new TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry(
			$container,
			$container->get(LoggerFactory::class),
		);
		// Core middleware. Names are stable contract — once shipped, don't
		// rename without a deprecation cycle (sites have these in page records).
		$registry->register('auth', TotalCMS\Domain\Builder\PageMiddleware\PageAuthMiddleware::class);

		return $registry;
	},

	// MCP (Model Context Protocol) Server.
	// ToolRegistry is a singleton; each tool's register() method is invoked at
	// container build time so the registry is fully populated before any
	// request reaches McpServerFactory. Tools then expose persona-aware
	// description builders (Phase 1) that the factory invokes per-request to
	// render the field catalog matching the resolved persona.
	ToolRegistry::class => function (ContainerInterface $container): ToolRegistry {
		$registry = new ToolRegistry();

		// Admin tools (require API key with /mcp scope).
		$container->get(SiteInfoTool::class)->register($registry);
		$container->get(SchemaTools::class)->register($registry);
		$container->get(CacheTools::class)->register($registry);
		$container->get(ExtensionTools::class)->register($registry);
		$container->get(CollectionTools::class)->register($registry);
		$container->get(ObjectTools::class)->register($registry);

		// Public tools (work for both admin and public personas; per-collection
		// access is enforced inside each handler via McpSchemaResolver).
		$container->get(QueryCollectionTool::class)->register($registry);
		$container->get(GetObjectTool::class)->register($registry);
		$container->get(SearchCollectionTool::class)->register($registry);
		$container->get(ListCollectionsTool::class)->register($registry);
		$container->get(DescribeCollectionTool::class)->register($registry);
		$container->get(SearchCollectionsTool::class)->register($registry);
		$container->get(GetResourceTool::class)->register($registry);

		// DataView tools (Phase 2 Chunk F) — parallel surface to the
		// collection tools, scoped to views. Public persona only sees views
		// marked mcp.access: 'public' per the per-view config; the tools
		// enforce that at handler time.
		$container->get(ListViewsTool::class)->register($registry);
		$container->get(QueryViewTool::class)->register($registry);
		$container->get(GetViewTool::class)->register($registry);
		$container->get(DescribeViewTool::class)->register($registry);

		return $registry;
	},

	// ResourceRegistry is a singleton, fully populated at container build time
	// via CollectionResourceRegistrar + DataViewResourceRegistrar. The DataView
	// registrar adds per-view tcms://view/{id} resources plus a single
	// tcms://view/{id} template. Per-resource access is enforced by
	// McpServerFactory::build()'s persona-filtered iteration.
	ResourceRegistry::class => function (ContainerInterface $container): ResourceRegistry {
		$registry = new ResourceRegistry();
		$container->get(CollectionResourceRegistrar::class)->registerAll($registry);
		$container->get(DataViewResourceRegistrar::class)->registerAll($registry);

		return $registry;
	},

	// MCP session store. Sessions are short-lived (1h TTL), per-client transport
	// state — same conceptual shape as PHP sessions, which T3 also keeps under
	// tmpdir. Not domain data, so doesn't belong in tcms-data/.system. Operator
	// "clear cache" flows don't touch this; session expiry handles cleanup.
	McpSessionStoreInterface::class => function (ContainerInterface $container): McpSessionStoreInterface {
		$dir = $container->get(Config::class)->tmpdir . '/mcp-sessions';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		return new McpFileSessionStore($dir, 3600);
	},

	// McpToolsValidator needs an explicit definition so it gets a logger bound
	// to the mcp-activity log channel (same as SchemaToolRegistrar).
	// Config is injected so the prefix-inclusive 64-char length check can
	// read mcp.toolPrefix at save time.
	McpToolsValidator::class => fn (ContainerInterface $container): McpToolsValidator => new McpToolsValidator(
		$container->get(ToolRegistry::class),
		$container->get(LoggerFactory::class)
			->channelLogger(LogChannel::McpToolsValidator, Level::Debug),
		$container->get(Config::class),
	),

	// SchemaToolRegistrar needs an explicit definition so it gets the same
	// mcp-activity logger as McpServerFactory rather than the generic
	// LoggerInterface (which is not bound to a concrete class by default).
	SchemaToolRegistrar::class => fn (ContainerInterface $container): SchemaToolRegistrar => new SchemaToolRegistrar(
		$container->get(CollectionRepository::class),
		$container->get(SavedQueryToolFactory::class),
		$container->get(LoggerFactory::class)
			->channelLogger(LogChannel::McpSchemaTools, Level::Debug),
	),

	// DataViewDependencyResolver needs an explicit definition because LoggerInterface
	// is not bound to a concrete class by default in this container.
	DataViewDependencyResolver::class => fn (ContainerInterface $container): DataViewDependencyResolver => new DataViewDependencyResolver(
		$container->get(IndexReader::class),
		$container->get(LoggerFactory::class)->channelLogger(LogChannel::DataViews),
	),

	// PromptDiscoveryService needs an explicit definition so it gets the mcp-activity
	// logger channel. PromptRenderer and PromptRegistrar autowire from type hints.
	PromptDiscoveryService::class => fn (ContainerInterface $container): PromptDiscoveryService => new PromptDiscoveryService(
		$container->get(IndexFilter::class),
		$container->get(CollectionRepository::class),
		$container->get(LoggerFactory::class)
			->channelLogger(LogChannel::McpPrompts, Level::Debug),
	),

	// McpServerFactory needs an explicit definition for its custom logger
	// handler — `mcp-activity.log` at Debug level so the SDK's per-call
	// dispatch messages ("Executing tool …" / "Tool executed successfully"
	// / "Error while executing tool") get captured. The Info default would
	// skip those debug messages, leaving the activity trace blank.
	//
	// McpAuth, McpDescriptionResolver, and McpSchemaResolver are autowired —
	// their dependencies (Config, ApiKeyAuthenticator, SchemaFetcher, etc.)
	// all resolve through the container without custom factory logic.
	McpServerFactory::class => fn (ContainerInterface $container): McpServerFactory => new McpServerFactory(
		$container->get(ToolRegistry::class),
		$container->get(ResourceRegistry::class),
		$container->get(McpSubscriptionManager::class),
		$container->get(Config::class),
		$container->get(McpSessionStoreInterface::class),
		$container->get(LoggerFactory::class)
			->channelLogger(LogChannel::McpActivity, Level::Debug),
		$container->get(SchemaToolRegistrar::class),
		$container->get(PromptDiscoveryService::class),
		$container->get(PromptRegistrar::class),
		$container->get(ExtensionManager::class),
	),

	// Subscription storage: reverse URI→sessionIds index at
	// {tmpdir}/mcp-subscriptions.json. Lives OUTSIDE /mcp-sessions/ because
	// McpSessionInvalidator::invalidateAll() wipes every file in that
	// directory whenever the tool surface changes; the subscription index
	// outlives session invalidations.
	SubscriptionIndex::class => fn (ContainerInterface $container): SubscriptionIndex => new SubscriptionIndex(
		$container->get(Config::class)->tmpdir . '/mcp-subscriptions.json',
	),

	// McpSubscriptionManager wraps the SDK default with reverse-index writes.
	// Logger writes to mcp-activity.log alongside other MCP traces so
	// index-write failures surface in the standard MCP log file.
	McpSubscriptionManager::class => fn (ContainerInterface $container): McpSubscriptionManager => new McpSubscriptionManager(
		$container->get(SubscriptionIndex::class),
		$container->get(LoggerFactory::class)
				->channelLogger(LogChannel::McpSubscription, Level::Debug),
	),

	// McpNotificationService fans out notifications/resources/updated to
	// every subscribed session when an object.* event fires. The McpServerFactory
	// builds the Protocol per request via the SDK; we cannot reuse that one
	// here, but Protocol holds no per-session state — its constructor is
	// session-agnostic and sendNotification takes session as a parameter. We
	// build one Protocol against the SessionManager and reuse it across calls.
	ResourceNotifier::class => fn (ContainerInterface $container): ResourceNotifier => $container->get(McpNotificationService::class),

	McpNotificationService::class => function (ContainerInterface $container): McpNotificationService {
		$sessionManager = new McpSessionManager($container->get(McpSessionStoreInterface::class));
		$protocol       = new McpProtocol(
			requestHandlers: [],
			notificationHandlers: [],
			messageFactory: McpMessageFactory::make(),
			sessionManager: $sessionManager,
		);

		return new McpNotificationService(
			$container->get(SubscriptionIndex::class),
			$container->get(McpSubscriptionManager::class),
			$protocol,
			$sessionManager,
			$container->get(LoggerFactory::class)
				->channelLogger(LogChannel::McpNotification, Level::Debug),
		);
	},

	// === OAuth ===
	//
	// Only the entries with non-type-hinted constructor args (string paths,
	// integer TTLs) and the two league classes built via OAuthServerFactory
	// need explicit definitions. Everything else (league adapters, services,
	// admin actions) autowires from constructor type hints.

	OAuthClientRepository::class => fn (ContainerInterface $container): OAuthClientRepository => new OAuthClientRepository(
		$container->get(Config::class)->datadir . '/.system/oauth-clients.json',
	),

	OAuthGrantRepository::class => fn (ContainerInterface $container): OAuthGrantRepository => new OAuthGrantRepository(
		$container->get(Config::class)->datadir . '/.system/oauth-grants.json',
	),

	OAuthRevocationList::class => fn (ContainerInterface $container): OAuthRevocationList => new OAuthRevocationList(
		$container->get(CacheManager::class),
		3600, // TODO: derive from $config->oauth['accessTokenTtl'] DateInterval if needed
	),

	OAuthReplayDetector::class => fn (ContainerInterface $container): OAuthReplayDetector => new OAuthReplayDetector(
		$container->get(CacheManager::class),
		refreshTokenTtlSeconds: 30 * 24 * 3600, // 30 days; matches default refresh TTL
	),

	AuthorizationServer::class => fn (ContainerInterface $container): AuthorizationServer => $container->get(OAuthServerFactory::class)->buildAuthorizationServer(),

	ResourceServer::class => fn (ContainerInterface $container): ResourceServer => $container->get(OAuthServerFactory::class)->buildResourceServer(),

	OAuthActivityLogger::class => fn (ContainerInterface $container): OAuthActivityLogger => new OAuthActivityLogger(
		$container->get(LoggerFactory::class)
			->channelLogger(LogChannel::OAuthActivity, Level::Info),
	),

	TotalCMS\Domain\Automation\Service\AutomationActivityLogger::class => fn (ContainerInterface $container): TotalCMS\Domain\Automation\Service\AutomationActivityLogger => new TotalCMS\Domain\Automation\Service\AutomationActivityLogger(
		$container->get(LoggerFactory::class)
			->channelLogger(LogChannel::AutomationsActivity, Level::Info),
	),

	// === Search Providers Phase 5 ===

	SearchProviderRegistry::class => function (ContainerInterface $container): SearchProviderRegistry {
		$registry = new SearchProviderRegistry();
		// Built-in text provider is always registered.
		$registry->register($container->get(TextSearchProvider::class));

		return $registry;
	},

	// TextSearchProvider autowires (IndexFilter + ObjectSearcher deps,
	// both resolvable by PHP-DI). No explicit entry needed.

	SearchService::class => fn (ContainerInterface $container): SearchService => new SearchService(
		$container->get(SearchProviderRegistry::class),
		$container->get(TextSearchProvider::class),
		$container->get(LoggerFactory::class)
				->channelLogger(LogChannel::Search, Level::Info),
		$container->get(Config::class),
		$container->get(CollectionFetcher::class),
	),

	// Bind the interface to the concrete implementation so PHP-DI autowires
	// SearchServiceInterface injections (e.g. in the MCP search tools) to
	// the same singleton SearchService instance.
	SearchServiceInterface::class => fn (ContainerInterface $container): SearchService => $container->get(SearchService::class),
];
