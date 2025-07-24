<?php

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Middlewares\TrailingSlash;
use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\Middleware\SessionStartMiddleware;
use Odan\Session\PhpSession;
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
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\CacheReporter;
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
use TotalCMS\Domain\ImageWorks\Service\TextWatermark;
use TotalCMS\Domain\Import\TotalCmsOneImporter;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Media\Generator\BarcodeGenerator;
use TotalCMS\Domain\Media\Generator\QRGenerator;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\PropertyDataProcessor;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Twig\Adapter\BarcodeTwigAdapter;
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
use TotalCMS\Middleware\CSRFProtectionMiddleware;
use TotalCMS\Middleware\PreviewRouteMiddleware;
use TotalCMS\Middleware\SentryMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

return [
	// Application settings
	Config::class => function () {
		return Config::init();
	},

	App::class => function (ContainerInterface $container) {
		AppFactory::setContainer($container);

		return AppFactory::create();
	},

	SessionStartMiddleware::class => function (ContainerInterface $container) {
		return new SessionStartMiddleware($container->get(PhpSession::class));
	},

	PhpSession::class => function (ContainerInterface $container) {
		return new PhpSession($container->get(Config::class)->session);
	},

	ResponseFactoryInterface::class => function (ContainerInterface $container) {
		return $container->get(App::class)->getResponseFactory();
	},

	ServerRequestFactoryInterface::class => function (ContainerInterface $container) {
		return $container->get(Psr17Factory::class);
	},

	StreamFactoryInterface::class => function (ContainerInterface $container) {
		return $container->get(Psr17Factory::class);
	},

	UploadedFileFactoryInterface::class => function (ContainerInterface $container) {
		return $container->get(Psr17Factory::class);
	},

	UriFactoryInterface::class => function (ContainerInterface $container) {
		return $container->get(Psr17Factory::class);
	},

	RouteParserInterface::class => function (ContainerInterface $container) {
		return $container->get(App::class)->getRouteCollector()->getRouteParser();
	},

	// The logger factory
	LoggerFactory::class => function (ContainerInterface $container) {
		return new LoggerFactory($container->get(Config::class)->logger);
	},

	// The data dir iterator factory
	StorageFilesystemAdapter::class => function (ContainerInterface $container) {
		$rootPath   = $container->get(Config::class)->datadir;
		$filesystem = new Filesystem(new LocalFilesystemAdapter($rootPath));

		return new StorageFilesystemAdapter($filesystem);
	},

	StorageAdapterInterface::class => function (ContainerInterface $container) {
		return $container->get(StorageFilesystemAdapter::class);
	},

	BasePathMiddleware::class => function (ContainerInterface $container) {
		$app = $container->get(App::class);

		return new BasePathMiddleware($app);
	},

	ValidationExceptionMiddleware::class => function (ContainerInterface $container) {
		$factory = $container->get(ResponseFactoryInterface::class);

		return new ValidationExceptionMiddleware($factory, new ErrorDetailsResultTransformer(), new JsonEncoder());
	},

	PreviewRouteMiddleware::class => function (ContainerInterface $container) {
		$api = $container->get(Config::class)->api;

		return new PreviewRouteMiddleware($api);
	},

	SentryMiddleware::class => function (ContainerInterface $container) {
		$config = (array)$container->get(Config::class)->sentry;

		return new SentryMiddleware($config);
	},

	ErrorMiddleware::class => function (ContainerInterface $container) {
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

	DefaultErrorHandler::class => function (ContainerInterface $container) {
		return new DefaultErrorHandler(
			$container->get(JsonRenderer::class),
			$container->get(ResponseFactoryInterface::class),
			$container->get(LoggerFactory::class),
			$container->get(OPcacheService::class)
		);
	},

	PhpRenderer::class => function (ContainerInterface $container) {
		return new PhpRenderer($container->get(Config::class)->template);
	},

	TrailingSlash::class => function (ContainerInterface $container) {
		return new TrailingSlash();
	},

	BufferController::class => function (ContainerInterface $container) {
		return new BufferController();
	},

	FakerFactory::class => function (ContainerInterface $container) {
		return new FakerFactory(
			$container->get(Config::class)
		);
	},

	IndexReader::class => function (ContainerInterface $container) {
		return new IndexReader(
			$container->get(IndexRepository::class),
			$container->get(IndexBuilder::class),
		);
	},

	ObjectFetcher::class => function (ContainerInterface $container) {
		return new ObjectFetcher($container->get(ObjectRepository::class));
	},

	PropertyFetcher::class => function (ContainerInterface $container) {
		return new PropertyFetcher($container->get(ObjectFetcher::class));
	},

	PropertyDataProcessorInterface::class => function (ContainerInterface $container) {
		return new PropertyDataProcessor();
	},

	PropertyDataProcessor::class => function (ContainerInterface $container) {
		return $container->get(PropertyDataProcessorInterface::class);
	},

	TotalFormFactory::class => function (ContainerInterface $container) {
		return new TotalFormFactory(
			$container->get(Config::class),
			$container->get(ObjectFetcher::class),
			$container->get(CollectionFetcher::class),
			$container->get(CollectionLister::class),
			$container->get(IndexReader::class),
			$container->get(SchemaFetcher::class),
			$container->get(SchemaLister::class),
			$container->get(SchemaFactory::class),
			$container->get(CSRFTokenManager::class),
		);
	},

	GridRenderer::class => function (ContainerInterface $container) {
		return new GridRenderer();
	},

	TotalCMSTwigAdapter::class => function (ContainerInterface $container) {
		return new TotalCMSTwigAdapter(
			$container->get(Config::class),
			$container->get(IndexReader::class),
			$container->get(IndexSearcher::class),
			$container->get(ObjectFetcher::class),
			$container->get(CollectionLister::class),
			$container->get(CollectionFetcher::class),
			$container->get(SchemaLister::class),
			$container->get(SchemaFetcher::class),
			$container->get(TotalFormFactory::class),
			$container->get(ServerChecker::class),
			$container->get(CacheReporter::class),
			$container->get(LogAnalyzer::class),
			$container->get(PhpSession::class),
			$container->get(AccessManager::class),
			$container->get(FileAccessManager::class),
			$container->get(ImageCacheService::class),
			$container->get(GridRenderer::class),
		);
	},

	TotalCMSTwigPatterns::class => function (ContainerInterface $container) {
		return new TotalCMSTwigPatterns();
	},

	TotalCMSTwigExtension::class => function (ContainerInterface $container) {
		return new TotalCMSTwigExtension(
			$container->get(TotalCMSTwigAdapter::class),
			$container->get(TotalCMSTwigPatterns::class),
			$container->get(FakerFactory::class),
			$container->get(QRCodeTwigAdapter::class),
			$container->get(BarcodeTwigAdapter::class),
			$container->get(PhpSession::class),
			$container->get(CSRFTokenManager::class),
		);
	},

	QRCodeTwigAdapter::class => function (ContainerInterface $container) {
		return new QRCodeTwigAdapter($container->get(QRGenerator::class));
	},

	QRGenerator::class => function (ContainerInterface $container) {
		return new QRGenerator();
	},

	BarcodeGenerator::class => function (ContainerInterface $container) {
		return new BarcodeGenerator();
	},

	BarcodeTwigAdapter::class => function (ContainerInterface $container) {
		return new BarcodeTwigAdapter($container->get(BarcodeGenerator::class));
	},

	FileUploadValidator::class => function (ContainerInterface $container) {
		return new FileUploadValidator();
	},

	Cipher::class => function (ContainerInterface $container) {
		return new Cipher();
	},

	CSRFTokenManager::class => function (ContainerInterface $container) {
		return new CSRFTokenManager(
			$container->get(PhpSession::class)
		);
	},

	CSRFProtectionMiddleware::class => function (ContainerInterface $container) {
		return new CSRFProtectionMiddleware(
			$container->get(CSRFTokenManager::class)
		);
	},

	TwigEngine::class => function (ContainerInterface $container) {
		return new TwigEngine(
			$container->get(Config::class),
			$container->get(TotalCMSTwigExtension::class)
		);
	},

	// Cache Services
	FilesystemService::class => function (ContainerInterface $container) {
		return new FilesystemService($container->get(Config::class));
	},

	OPcacheService::class => function (ContainerInterface $container) {
		return new OPcacheService();
	},

	RedisService::class => function (ContainerInterface $container) {
		return new RedisService($container->get(Config::class));
	},

	MemcachedService::class => function (ContainerInterface $container) {
		return new MemcachedService($container->get(Config::class));
	},

	CacheReporter::class => function (ContainerInterface $container) {
		return new CacheReporter(
			$container->get(FilesystemService::class),
			$container->get(OPcacheService::class),
			$container->get(RedisService::class),
			$container->get(MemcachedService::class),
		);
	},

	CacheManager::class => function (ContainerInterface $container) {
		return new CacheManager(
			$container->get(FilesystemService::class),
			$container->get(OPcacheService::class),
			$container->get(RedisService::class),
			$container->get(MemcachedService::class),
			$container->get(TextWatermark::class)
		);
	},

	SchemaRepository::class => function (ContainerInterface $container) {
		return new SchemaRepository(
			$container->get(StorageAdapterInterface::class),
			$container->get(SchemaFactory::class),
			$container->get(CacheManager::class)
		);
	},

	ImageCacheService::class => function (ContainerInterface $container) {
		return new ImageCacheService(
			$container->get(Config::class)
		);
	},

	IndexSearcher::class => function (ContainerInterface $container) {
		return new IndexSearcher($container->get(IndexReader::class));
	},

	UserValidationService::class => function (ContainerInterface $container) {
		return new UserValidationService(
			$container->get(IndexSearcher::class),
			$container->get(ObjectFetcher::class),
			$container->get(Config::class),
		);
	},

	AccessManager::class => function (ContainerInterface $container) {
		return new AccessManager(
			$container->get(PhpSession::class),
			$container->get(Config::class),
			$container->get(UserValidationService::class),
			$container->get(LoggerFactory::class),
		);
	},

	TotalCmsOneImporter::class => function (ContainerInterface $container) {
		return new TotalCmsOneImporter(
			$container->get(CollectionFetcher::class),
			$container->get(CollectionFactory::class),
			$container->get(CollectionRepository::class),
			$container->get(JobQueuer::class),
			$container->get(LoggerFactory::class),
		);
	},

	JumpStartExporter::class => function (ContainerInterface $container) {
		return new JumpStartExporter(
			$container->get(CollectionLister::class),
			$container->get(SchemaLister::class),
			$container->get(SchemaFetcher::class),
			$container->get(ObjectFetcher::class),
			$container->get(IndexReader::class),
			new JumpStartData(),
			$container->get(CacheManager::class),
			$container->get(LoggerFactory::class),
		);
	},

	FactoryImporter::class => function (ContainerInterface $container) {
		return new FactoryImporter(
			$container->get(ObjectFactory::class),
			$container->get(ObjectRepository::class),
			$container->get(IndexBuilder::class),
			$container->get(CollectionFetcher::class),
			$container->get(SchemaFetcher::class),
			$container->get(PropertyRepository::class),
			$container->get(CacheManager::class),
			$container->get(FakerFactory::class),
			$container->get(LoggerFactory::class),
		);
	},

	JumpStartImporter::class => function (ContainerInterface $container) {
		return new JumpStartImporter(
			$container->get(CollectionFetcher::class),
			$container->get(CollectionSaver::class),
			$container->get(ObjectFetcher::class),
			$container->get(ObjectSaver::class),
			$container->get(SchemaSaver::class),
			$container->get(FactoryImporter::class),
			$container->get(LoggerFactory::class),
		);
	},

	TextWatermark::class => function (ContainerInterface $container) {
		return new TextWatermark(
			$container->get(StorageAdapterInterface::class)
		);
	},

	GlideFactory::class => function (ContainerInterface $container) {
		return new GlideFactory(
			$container->get(StorageAdapterInterface::class),
			$container->get(Config::class),
			$container->get(TextWatermark::class)
		);
	},
];
