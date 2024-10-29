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
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Twig\QRCodeTwigAdapter;
use TotalCMS\Domain\Twig\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\TotalCMSTwigPatterns;
use TotalCMS\Domain\Twig\TwigCacheCleaner;
use TotalCMS\Domain\Twig\TwigEngine;
use TotalCMS\Factory\FakerFactory;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Handler\DefaultErrorHandler;
use TotalCMS\Middleware\PreviewRouteMiddleware;
use TotalCMS\Middleware\SentryMiddleware;
use TotalCMS\Support\Config;
use TotalCMS\Utils\LogAnalyzer;
use TotalCMS\Utils\QRGenerator;
use TotalCMS\Utils\ServerChecker;

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

	TotalFormFactory::class => function (ContainerInterface $container) {
		return new TotalFormFactory(
			$container->get(Config::class),
			$container->get(ObjectFetcher::class),
			$container->get(CollectionFetcher::class),
			$container->get(IndexReader::class),
			$container->get(SchemaFetcher::class),
			$container->get(SchemaLister::class),
		);
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
			$container->get(LogAnalyzer::class),
			$container->get(PhpSession::class),
			$container->get(AccessManager::class),
			$container->get(FileAccessManager::class),
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
			$container->get(PhpSession::class),
		);
	},

	QRCodeTwigAdapter::class => function (ContainerInterface $container) {
		return new QRCodeTwigAdapter($container->get(QRGenerator::class));
	},

	QRGenerator::class => function (ContainerInterface $container) {
		return new QRGenerator();
	},

	TwigEngine::class => function (ContainerInterface $container) {
		return new TwigEngine($container->get(Config::class), $container->get(TotalCMSTwigExtension::class));
	},

	TwigCacheCleaner::class => function (ContainerInterface $container) {
		return new TwigCacheCleaner($container->get(Config::class));
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
];
