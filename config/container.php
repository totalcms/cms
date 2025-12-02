<?php

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Middlewares\TrailingSlash;
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
use Selective\BasePath\BasePathMiddleware;
use Selective\Validation\Encoder\JsonEncoder;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Selective\Validation\Transformer\ErrorDetailsResultTransformer;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteParserInterface;
use Slim\Middleware\ErrorMiddleware;
use Slim\Views\PhpRenderer;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\LogoutService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\PasswordResetService;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\Service\APCuService;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Cache\Service\FilesystemService;
use TotalCMS\Domain\Cache\Service\MemcachedService;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Cache\Service\RedisService;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Factory\Service\FakerFactory;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\Import\TotalCmsOneImporter;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Media\Generator\BarcodeGenerator;
use TotalCMS\Domain\Media\Generator\QRGenerator;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DeckItemFetcher;
use TotalCMS\Domain\Property\Service\DeckItemRemover;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Property\Service\DeckItemUpdater;
use TotalCMS\Domain\Property\Service\PropertyDataProcessor;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;
use TotalCMS\Domain\Property\Service\PropertyFactory;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Domain\Settings\Repository\InstallationRepository;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\DataDirectoryManager;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Settings\Services\SettingsValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Domain\Twig\Adapter\BarcodeTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\EditionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\QRCodeTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigPatterns;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Handler\DefaultErrorHandler;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\CollectionMetaAccessMiddleware;
use TotalCMS\Middleware\Access\DocsAccessMiddleware;
use TotalCMS\Middleware\Access\MailerAccessMiddleware;
use TotalCMS\Middleware\Access\PlaygroundAccessMiddleware;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Access\TemplateAccessMiddleware;
use TotalCMS\Middleware\Access\UtilsAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Development\DevModeMiddleware;
use TotalCMS\Middleware\Development\SentryMiddleware;
use TotalCMS\Middleware\License\EditionFeatureMiddleware;
use TotalCMS\Middleware\License\LicenseValidationMiddleware;
use TotalCMS\Middleware\Response\PreviewRouteMiddleware;
use TotalCMS\Middleware\Security\CSRFProtectionMiddleware;
use TotalCMS\Middleware\Security\RateLimitMiddleware;
use TotalCMS\Middleware\SetupCheckMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

return [
	// Application settings
	Config::class => fn (): Config => Config::init(),

	App::class => function (ContainerInterface $container): App {
		AppFactory::setContainer($container);

		return AppFactory::create();
	},

	SessionStartMiddleware::class => fn (ContainerInterface $container): SessionStartMiddleware => new SessionStartMiddleware($container->get(PhpSession::class)),

	PhpSession::class => function (ContainerInterface $container): PhpSession {
		$sessionConfig = $container->get(Config::class)->session;

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

	// The logger factory
	LoggerFactory::class => fn (ContainerInterface $container): LoggerFactory => new LoggerFactory($container->get(Config::class)->logger),

	// The data dir iterator factory
	StorageFilesystemAdapter::class => function (ContainerInterface $container): StorageFilesystemAdapter {
		$rootPath = $container->get(Config::class)->datadir;

		// Note: LocalFilesystemAdapter may create the directory on first write operation
		// but not on instantiation. The setup wizard is responsible for creating the directory.
		$filesystem = new Filesystem(new LocalFilesystemAdapter($rootPath));

		return new StorageFilesystemAdapter($filesystem);
	},

	StorageAdapterInterface::class => fn (ContainerInterface $container) => $container->get(StorageFilesystemAdapter::class),

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

	TrailingSlash::class => fn (ContainerInterface $container): TrailingSlash => new TrailingSlash(),

	BufferController::class => fn (ContainerInterface $container): BufferController => new BufferController(),

	FakerFactory::class => fn (ContainerInterface $container): FakerFactory => new FakerFactory(
		$container->get(Config::class)
	),

	IndexReader::class => fn (ContainerInterface $container): IndexReader => new IndexReader(
		$container->get(IndexRepository::class),
		$container->get(IndexBuilder::class),
	),

	IndexFilter::class => fn (ContainerInterface $container): IndexFilter => new IndexFilter(
		$container->get(IndexReader::class),
	),

	ObjectFetcher::class => fn (ContainerInterface $container): ObjectFetcher => new ObjectFetcher($container->get(ObjectRepository::class)),

	PropertyFetcher::class => fn (ContainerInterface $container): PropertyFetcher => new PropertyFetcher($container->get(ObjectFetcher::class)),

	PropertyDataProcessorInterface::class => fn (ContainerInterface $container): PropertyDataProcessor => new PropertyDataProcessor(),

	PropertyDataProcessor::class => fn (ContainerInterface $container) => $container->get(PropertyDataProcessorInterface::class),

	TotalFormFactory::class => fn (ContainerInterface $container): TotalFormFactory => new TotalFormFactory(
		$container->get(Config::class),
		$container->get(PhpSession::class),
		$container->get(ObjectFetcher::class),
		$container->get(CollectionFetcher::class),
		$container->get(CollectionLister::class),
		$container->get(IndexReader::class),
		$container->get(IndexFilter::class),
		$container->get(SchemaFetcher::class),
		$container->get(SchemaLister::class),
		$container->get(AccessGroupLister::class),
		$container->get(SchemaFactory::class),
		$container->get(TemplateRepository::class),
		$container->get(CSRFTokenManager::class),
		$container->get(SettingsSchemaFetcher::class),
		$container->get(SettingsFetcher::class),
		$container->get(JobManager::class),
	),

	GridRenderer::class => fn (ContainerInterface $container): GridRenderer => new GridRenderer(),

	EditionTwigAdapter::class => fn (ContainerInterface $container): EditionTwigAdapter => new EditionTwigAdapter(
		$container->get(EditionFeatureService::class),
	),

	TotalCMSTwigAdapter::class => fn (ContainerInterface $container): TotalCMSTwigAdapter => new TotalCMSTwigAdapter(
		$container->get(Config::class),
		$container->get(IndexReader::class),
		$container->get(IndexSearcher::class),
		$container->get(ObjectFetcher::class),
		$container->get(CollectionLister::class),
		$container->get(CollectionFetcher::class),
		$container->get(SchemaLister::class),
		$container->get(SchemaFetcher::class),
		$container->get(DeckCompatibilityChecker::class),
		$container->get(TemplateLister::class),
		$container->get(TotalFormFactory::class),
		$container->get(ServerChecker::class),
		$container->get(CacheReporter::class),
		$container->get(LogAnalyzer::class),
		$container->get(PhpSession::class),
		$container->get(AccessManager::class),
		$container->get(FileAccessManager::class),
		$container->get(AccessControlService::class),
		$container->get(ImageCacheService::class),
		$container->get(GridRenderer::class),
		$container->get(DevModeManager::class),
		$container->get(LicenseStatus::class),
		$container->get(EditionTwigAdapter::class),
		$container->get(JobManager::class),
	),

	TotalCMSTwigPatterns::class => fn (ContainerInterface $container): TotalCMSTwigPatterns => new TotalCMSTwigPatterns(),

	TotalCMSTwigExtension::class => fn (ContainerInterface $container): TotalCMSTwigExtension => new TotalCMSTwigExtension(
		$container->get(TotalCMSTwigAdapter::class),
		$container->get(TotalCMSTwigPatterns::class),
		$container->get(FakerFactory::class),
		$container->get(QRCodeTwigAdapter::class),
		$container->get(BarcodeTwigAdapter::class),
		$container->get(PhpSession::class),
		$container->get(CSRFTokenManager::class),
	),

	QRCodeTwigAdapter::class => fn (ContainerInterface $container): QRCodeTwigAdapter => new QRCodeTwigAdapter($container->get(QRGenerator::class)),

	QRGenerator::class => fn (ContainerInterface $container): QRGenerator => new QRGenerator(
		$container->get(EditionFeatureService::class)
	),

	BarcodeGenerator::class => fn (ContainerInterface $container): BarcodeGenerator => new BarcodeGenerator(
		$container->get(EditionFeatureService::class)
	),

	BarcodeTwigAdapter::class => fn (ContainerInterface $container): BarcodeTwigAdapter => new BarcodeTwigAdapter($container->get(BarcodeGenerator::class)),

	FileUploadValidator::class => fn (ContainerInterface $container): FileUploadValidator => new FileUploadValidator(),

	Cipher::class => fn (ContainerInterface $container): Cipher => new Cipher(),

	CSRFTokenManager::class => fn (ContainerInterface $container): CSRFTokenManager => new CSRFTokenManager(
		$container->get(PhpSession::class)
	),

	CSRFProtectionMiddleware::class => fn (ContainerInterface $container): CSRFProtectionMiddleware => new CSRFProtectionMiddleware(
		$container->get(CSRFTokenManager::class)
	),

	AuthMiddleware::class => fn (ContainerInterface $container): AuthMiddleware => new AuthMiddleware(
		$container->get(ResponseFactoryInterface::class),
		$container->get(PhpSession::class),
		$container->get(Config::class),
		$container->get(AccessManager::class),
		$container->get(PersistentLoginService::class),
	),

	CollectionAccessMiddleware::class => fn (ContainerInterface $container): CollectionAccessMiddleware => new CollectionAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	CollectionMetaAccessMiddleware::class => fn (ContainerInterface $container): CollectionMetaAccessMiddleware => new CollectionMetaAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	SchemaAccessMiddleware::class => fn (ContainerInterface $container): SchemaAccessMiddleware => new SchemaAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	TemplateAccessMiddleware::class => fn (ContainerInterface $container): TemplateAccessMiddleware => new TemplateAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	UtilsAccessMiddleware::class => fn (ContainerInterface $container): UtilsAccessMiddleware => new UtilsAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	MailerAccessMiddleware::class => fn (ContainerInterface $container): MailerAccessMiddleware => new MailerAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	PlaygroundAccessMiddleware::class => fn (ContainerInterface $container): PlaygroundAccessMiddleware => new PlaygroundAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	DocsAccessMiddleware::class => fn (ContainerInterface $container): DocsAccessMiddleware => new DocsAccessMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	AdminOnlyMiddleware::class => fn (ContainerInterface $container): AdminOnlyMiddleware => new AdminOnlyMiddleware(
		$container->get(UserValidationService::class),
		$container->get(AccessControlService::class),
		$container->get(PhpSession::class),
		$container->get(JsonRenderer::class),
		$container->get(TwigRenderer::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(Config::class),
		$container->get(OperationDetector::class),
		$container->get(LoggerFactory::class),
	),

	DevModeMiddleware::class => fn (ContainerInterface $container): DevModeMiddleware => new DevModeMiddleware(
		$container->get(DevModeManager::class),
		$container->get(OPcacheService::class)
	),

	LicenseValidationMiddleware::class => fn (ContainerInterface $container): LicenseValidationMiddleware => new LicenseValidationMiddleware(
		$container->get(LicenseValidator::class),
		$container->get(Config::class),
		$container->get(ResponseFactoryInterface::class),
		$container->get(LoggerFactory::class),
	),

	TwigEngine::class => fn (ContainerInterface $container): TwigEngine => new TwigEngine(
		$container->get(Config::class),
		$container->get(TotalCMSTwigExtension::class),
		$container->get(DevModeManager::class)
	),

	TwigRenderer::class => fn (ContainerInterface $container): TwigRenderer => new TwigRenderer(
		$container->get(TwigEngine::class)
	),

	RedirectRenderer::class => fn (ContainerInterface $container): RedirectRenderer => new RedirectRenderer(
		$container->get(RouteParserInterface::class)
	),

	// Cache Services
	FilesystemService::class => fn (ContainerInterface $container): FilesystemService => new FilesystemService($container->get(Config::class)),

	OPcacheService::class => fn (ContainerInterface $container): OPcacheService => new OPcacheService(),

	RedisService::class => fn (ContainerInterface $container): RedisService => new RedisService($container->get(Config::class)),

	MemcachedService::class => fn (ContainerInterface $container): MemcachedService => new MemcachedService($container->get(Config::class)),

	APCuService::class => fn (ContainerInterface $container): APCuService => new APCuService($container->get(Config::class)),

	CacheReporter::class => fn (ContainerInterface $container): CacheReporter => new CacheReporter(
		$container->get(FilesystemService::class),
		$container->get(OPcacheService::class),
		$container->get(RedisService::class),
		$container->get(MemcachedService::class),
		$container->get(APCuService::class),
		$container->get(DevModeManager::class),
	),

	CacheManager::class => fn (ContainerInterface $container): CacheManager => new CacheManager(
		$container->get(FilesystemService::class),
		$container->get(OPcacheService::class),
		$container->get(RedisService::class),
		$container->get(MemcachedService::class),
		$container->get(APCuService::class),
		$container->get(TextWatermarkFactory::class),
		$container->get(DevModeManager::class),
		$container->get(Config::class),
		$container->get(LoggerFactory::class)
	),

	DevModeManager::class => fn (ContainerInterface $container): DevModeManager => new DevModeManager(),

	SchemaRepository::class => fn (ContainerInterface $container): SchemaRepository => new SchemaRepository(
		$container->get(StorageAdapterInterface::class),
		$container->get(SchemaFactory::class),
		$container->get(CacheManager::class),
		$container->get(Config::class),
	),

	ImageCacheService::class => fn (ContainerInterface $container): ImageCacheService => new ImageCacheService(
		$container->get(Config::class),
		$container->get(CacheManager::class)
	),

	IndexSearcher::class => fn (ContainerInterface $container): IndexSearcher => new IndexSearcher($container->get(IndexReader::class)),

	UserValidationService::class => fn (ContainerInterface $container): UserValidationService => new UserValidationService(
		$container->get(IndexSearcher::class),
		$container->get(ObjectFetcher::class),
		$container->get(Config::class),
	),

	PasswordResetService::class => fn (ContainerInterface $container): PasswordResetService => new PasswordResetService(
		$container->get(CacheManager::class),
		$container->get(IndexSearcher::class),
		$container->get(ObjectFetcher::class),
		$container->get(ObjectUpdater::class),
		$container->get(Config::class),
		$container->get(LoggerFactory::class),
	),

	PersistentLoginService::class => fn (ContainerInterface $container): PersistentLoginService => new PersistentLoginService(
		$container->get(PhpSession::class),
		$container->get(Config::class),
		$container->get(UserValidationService::class),
	),

	LogoutService::class => fn (ContainerInterface $container): LogoutService => new LogoutService(
		$container->get(PhpSession::class),
		$container->get(LoggerFactory::class),
		$container->get(PersistentLoginService::class),
	),

	// License Services
	LicenseValidator::class => fn (ContainerInterface $container): LicenseValidator => new LicenseValidator(
		$container->get(Config::class),
		$container->get(CacheManager::class),
	),

	LicenseStatus::class => fn (ContainerInterface $container): LicenseStatus => new LicenseStatus(
		$container->get(LicenseValidator::class),
		$container->get(LoggerFactory::class),
	),

	EditionFeatureService::class => fn (ContainerInterface $container): EditionFeatureService => new EditionFeatureService(
		$container->get(LicenseValidator::class),
		$container->get(SettingsFetcher::class),
	),

	AccessManager::class => fn (ContainerInterface $container): AccessManager => new AccessManager(
		$container->get(PhpSession::class),
		$container->get(Config::class),
		$container->get(UserValidationService::class),
		$container->get(LoggerFactory::class),
	),

	AccessControlService::class => fn (ContainerInterface $container): AccessControlService => new AccessControlService(
		$container->get(UserValidationService::class),
		$container->get(AccessGroupLister::class),
		$container->get(PhpSession::class),
	),

	TotalCmsOneImporter::class => fn (ContainerInterface $container): TotalCmsOneImporter => new TotalCmsOneImporter(
		$container->get(CollectionFetcher::class),
		$container->get(CollectionFactory::class),
		$container->get(CollectionRepository::class),
		$container->get(IndexReader::class),
		$container->get(JobQueuer::class),
		$container->get(LoggerFactory::class),
	),

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

	FactoryImporter::class => fn (ContainerInterface $container): FactoryImporter => new FactoryImporter(
		$container->get(ObjectFactory::class),
		$container->get(ObjectRepository::class),
		$container->get(IndexBuilder::class),
		$container->get(CollectionFetcher::class),
		$container->get(SchemaFetcher::class),
		$container->get(PropertyRepository::class),
		$container->get(CacheManager::class),
		$container->get(FakerFactory::class),
		$container->get(LoggerFactory::class),
	),

	JumpStartImporter::class => fn (ContainerInterface $container): JumpStartImporter => new JumpStartImporter(
		$container->get(CollectionFetcher::class),
		$container->get(CollectionSaver::class),
		$container->get(ObjectFetcher::class),
		$container->get(ObjectSaver::class),
		$container->get(SchemaSaver::class),
		$container->get(TemplateSaver::class),
		$container->get(FactoryImporter::class),
		$container->get(LoggerFactory::class),
	),

	TextWatermarkFactory::class => fn (ContainerInterface $container): TextWatermarkFactory => new TextWatermarkFactory(
		$container->get(StorageAdapterInterface::class),
		$container->get(Config::class),
		$container->get(EditionFeatureService::class),
		$container->get(LoggerFactory::class)
	),

	GlideFactory::class => fn (ContainerInterface $container): GlideFactory => new GlideFactory(
		$container->get(StorageAdapterInterface::class),
		$container->get(Config::class),
	),

	// Property and Object Factories
	PropertyFactory::class => fn (ContainerInterface $container): PropertyFactory => new PropertyFactory(
		$container->get(SchemaFetcher::class),
		$container->get(DeckCompatibilityChecker::class),
	),

	AutogenIdService::class => fn (ContainerInterface $container): AutogenIdService => new AutogenIdService(
		$container->get(CollectionFetcher::class),
	),

	ObjectFactory::class => fn (ContainerInterface $container): ObjectFactory => new ObjectFactory(
		$container->get(SchemaFetcher::class),
		$container->get(PropertyFactory::class),
		$container->get(AutogenIdService::class),
	),

	// Deck Services
	DeckItemFetcher::class => fn (ContainerInterface $container): DeckItemFetcher => new DeckItemFetcher(
		$container->get(ObjectFetcher::class),
	),

	DeckItemSaver::class => fn (ContainerInterface $container): DeckItemSaver => new DeckItemSaver(
		$container->get(ObjectFetcher::class),
		$container->get(ObjectUpdater::class),
		$container->get(PropertyFactory::class),
	),

	DeckItemUpdater::class => fn (ContainerInterface $container): DeckItemUpdater => new DeckItemUpdater(
		$container->get(ObjectFetcher::class),
		$container->get(ObjectUpdater::class),
		$container->get(PropertyFactory::class),
	),

	DeckItemRemover::class => fn (ContainerInterface $container): DeckItemRemover => new DeckItemRemover(
		$container->get(ObjectFetcher::class),
		$container->get(ObjectUpdater::class),
	),

	// Schema Services
	DeckCompatibilityChecker::class => fn (ContainerInterface $container): DeckCompatibilityChecker => new DeckCompatibilityChecker(
		$container->get(SchemaFetcher::class),
	),

	// Settings Services
	SettingsSchemaFetcher::class => fn (ContainerInterface $container): SettingsSchemaFetcher => new SettingsSchemaFetcher(),

	// Settings Repositories
	SettingsRepository::class => fn (ContainerInterface $container): SettingsRepository => new SettingsRepository(
		$container->get(StorageFilesystemAdapter::class),
	),

	InstallationRepository::class => fn (ContainerInterface $container): InstallationRepository => new InstallationRepository(),

	// Settings Services
	SettingsFetcher::class => fn (ContainerInterface $container): SettingsFetcher => new SettingsFetcher(
		$container->get(SettingsRepository::class),
		$container->get(InstallationRepository::class),
	),

	SettingsValidator::class => fn (ContainerInterface $container): SettingsValidator => new SettingsValidator(),

	SettingsSaver::class => fn (ContainerInterface $container): SettingsSaver => new SettingsSaver(
		$container->get(SettingsFetcher::class),
		$container->get(SettingsValidator::class),
		$container->get(CacheManager::class),
		$container->get(SettingsRepository::class),
	),

	InstallationSettingsSaver::class => fn (ContainerInterface $container): InstallationSettingsSaver => new InstallationSettingsSaver(
		$container->get(CacheManager::class),
		$container->get(InstallationRepository::class),
	),

	DataDirectoryManager::class => fn (): DataDirectoryManager => new DataDirectoryManager(),

	// Mailer Services
	EmailSender::class => fn (ContainerInterface $container): EmailSender => new EmailSender(
		$container->get(Config::class),
		$container->get(LoggerFactory::class),
	),

	MailerFetcher::class => fn (ContainerInterface $container): MailerFetcher => new MailerFetcher(
		$container->get(ObjectRepository::class),
	),

	EmailService::class => fn (ContainerInterface $container): EmailService => new EmailService(
		$container->get(MailerFetcher::class),
		$container->get(EmailSender::class),
		$container->get(TwigEngine::class),
		$container->get(Config::class),
		$container->get(EditionFeatureService::class),
		$container->get(LoggerFactory::class),
	),

	RateLimitMiddleware::class => fn (ContainerInterface $container): RateLimitMiddleware => new RateLimitMiddleware(
		$container->get(APCuService::class),
		$container->get(JsonRenderer::class),
		$container->get(Config::class),
	),

	SetupCheckMiddleware::class => fn (ContainerInterface $container): SetupCheckMiddleware => new SetupCheckMiddleware(
		$container->get(Config::class),
		$container->get(RedirectRenderer::class),
	),
];
