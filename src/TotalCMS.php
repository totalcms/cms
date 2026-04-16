<?php

namespace TotalCMS;

use DI\Container;
use Monolog\Level;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Export\Service\CollectionZipper;
use TotalCMS\Domain\Export\Service\ObjectZipper;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Domain\Import\DeckCsvImporter;
use TotalCMS\Domain\Import\DeckJsonImporter;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPropertyIncrementer;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Service\DeckItemFetcher;
use TotalCMS\Domain\Property\Service\DeckItemRemover;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Property\Service\DeckItemUpdater;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Domain\Update\Service\UpdateDownloader;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Entry point for Total CMS PHP API.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class TotalCMS
{
	private BufferController $buffer;
	private readonly Container $container;
	private TwigEngine $twigEngine;
	private readonly LoggerInterface $logger;
	private readonly CacheManager $cacheManager;
	private PhpSession $session;
	private AccessManager $access;
	public Config $config;

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(bool $autoStartBuffer = true)
	{
		// Build PHP-DI Container instance
		$this->container = new Container(require PathResolver::packageRoot() . '/config/container.php');

		$loggerFactory = $this->container->get(LoggerFactory::class);
		$this->logger  = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');

		// Initialize services needed for both CLI and web
		$this->cacheManager = $this->container->get(CacheManager::class);
		$this->config       = $this->container->get(Config::class);

		// CLI mode, no need to start the session or buffer
		if (PHP_SAPI === 'cli' && !self::isPreview()) {
			return;
		}

		$preservedSessionData = [];
		$sessionStarted       = false;

		try {
			// Handle any existing session conflicts before creating PhpSession
			$preservedSessionData = $this->handleSessionConflict();

			$this->session = $this->container->get(PhpSession::class);
			$this->access  = $this->container->get(AccessManager::class);

			// Start session and restore data if needed
			if (!self::isPreview()) {
				$this->session->start();
				$sessionStarted = true;

				if ($preservedSessionData !== []) {
					$this->restorePreservedSessionData($preservedSessionData);
				}
			}

			$this->buffer     = $this->container->get(BufferController::class);
			$this->twigEngine = $this->container->get(TwigEngine::class);
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}

		// Start session if it wasn't started during preservation
		if (!self::isPreview() && !$sessionStarted) {
			$this->session->start();
		}

		if ($autoStartBuffer) {
			$this->startBuffer();
		}
	}

	/**
	 * Handle existing session conflicts based on configuration strategy.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,mixed> Preserved session data (if any)
	 */
	private function handleSessionConflict(): array
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			// No conflict, proceed normally
			return [];
		}

		$existingData = $_SESSION ?? [];
		$strategy     = $this->config->session['conflictStrategy'] ?? 'preserve';

		// Log the conflict for debugging
		$this->logger->debug('Session conflict detected', [
			'strategy'     => $strategy,
			'existingKeys' => array_keys($existingData),
		]);

		// Always destroy existing session so PhpSession can start cleanly
		session_destroy();

		// Return data based on strategy
		return match ($strategy) {
			'preserve' => $existingData,
			'replace'  => [],
			default    => [],
		};
	}

	/**
	 * Restore preserved session data as-is.
	 * External session data keeps its original keys.
	 * Total CMS will use namespaced keys (e.g., 'totalcms.auth.user') to avoid conflicts.
	 *
	 * @param array<string,mixed> $preservedData
	 */
	private function restorePreservedSessionData(array $preservedData): void
	{
		foreach ($preservedData as $key => $value) {
			$this->session->set($key, $value);
		}

		$this->logger->debug('Session data restored', [
			'restoredKeys' => array_keys($preservedData),
		]);
	}

	// ---------------------------------------------------------------------------------
	// Public methods to access Total CMS services
	// ---------------------------------------------------------------------------------
	public function container(): Container
	{
		return $this->container;
	}

	public function collectionLister(): CollectionLister
	{
		return $this->container->get(CollectionLister::class);
	}

	public function collectionFetcher(): CollectionFetcher
	{
		return $this->container->get(CollectionFetcher::class);
	}

	public function indexReader(): IndexReader
	{
		return $this->container->get(IndexReader::class);
	}

	public function indexSearcher(): IndexSearcher
	{
		return $this->container->get(IndexSearcher::class);
	}

	public function objectFetcher(): ObjectFetcher
	{
		return $this->container->get(ObjectFetcher::class);
	}

	public function propertyFetcher(): PropertyFetcher
	{
		return $this->container->get(PropertyFetcher::class);
	}

	public function jobRunner(): JobRunner
	{
		return $this->container->get(JobRunner::class);
	}

	/**
	 * Get the email service for sending emails via configured mailer templates.
	 *
	 * Usage:
	 *   $result = $totalcms->mailer()->sendEmail('error-notification', ['error' => $message]);
	 */
	public function mailer(): EmailService
	{
		return $this->container->get(EmailService::class);
	}

	/**
	 * Get the deck item saver for adding items to deck properties.
	 *
	 * Usage:
	 *   $totalcms->deckItemSaver()->saveDeckItem('users', $userId, 'deposits', $itemId, $itemData);
	 */
	public function deckItemSaver(): DeckItemSaver
	{
		return $this->container->get(DeckItemSaver::class);
	}

	/**
	 * Get the deck item updater for updating existing deck items.
	 *
	 * Usage:
	 *   $totalcms->deckItemUpdater()->updateDeckItem('users', $userId, 'deposits', $itemId, $itemData);
	 */
	public function deckItemUpdater(): DeckItemUpdater
	{
		return $this->container->get(DeckItemUpdater::class);
	}

	/**
	 * Get the deck item remover for deleting deck items.
	 *
	 * Usage:
	 *   $totalcms->deckItemRemover()->removeDeckItem('users', $userId, 'deposits', $itemId);
	 */
	public function deckItemRemover(): DeckItemRemover
	{
		return $this->container->get(DeckItemRemover::class);
	}

	/**
	 * Get the deck item fetcher for retrieving specific deck items.
	 *
	 * Usage:
	 *   $item = $totalcms->deckItemFetcher()->fetchDeckItem('users', $userId, 'deposits', $itemId);
	 */
	public function deckItemFetcher(): DeckItemFetcher
	{
		return $this->container->get(DeckItemFetcher::class);
	}

	/**
	 * Get the object saver for creating new objects.
	 *
	 * Usage:
	 *   $object = $totalcms->objectSaver()->saveObject('blog', ['id' => 'my-post', 'title' => 'Hello']);
	 */
	public function objectSaver(): ObjectSaver
	{
		return $this->container->get(ObjectSaver::class);
	}

	/**
	 * Get the object updater for updating existing objects.
	 *
	 * Usage:
	 *   $object = $totalcms->objectUpdater()->updateObject('blog', 'my-post', ['title' => 'Updated Title']);
	 */
	public function objectUpdater(): ObjectUpdater
	{
		return $this->container->get(ObjectUpdater::class);
	}

	/**
	 * Get the object remover for deleting objects.
	 *
	 * Usage:
	 *   $totalcms->objectRemover()->removeObject('blog', 'my-post');
	 */
	public function objectRemover(): ObjectRemover
	{
		return $this->container->get(ObjectRemover::class);
	}

	/**
	 * Get the object cloner for duplicating objects.
	 *
	 * Usage:
	 *   $clonedObject = $totalcms->objectCloner()->cloneObject('blog', 'my-post', 'my-post-copy');
	 */
	public function objectCloner(): ObjectCloner
	{
		return $this->container->get(ObjectCloner::class);
	}

	/**
	 * Get the property incrementer for incrementing/decrementing numeric properties.
	 *
	 * Usage:
	 *   $result = $totalcms->propertyIncrementer()->incrementProperty('products', 'item-1', 'stock', 5);
	 *   $result = $totalcms->propertyIncrementer()->decrementProperty('products', 'item-1', 'stock', 1);
	 */
	public function propertyIncrementer(): ObjectPropertyIncrementer
	{
		return $this->container->get(ObjectPropertyIncrementer::class);
	}

	/**
	 * Get the schema fetcher for retrieving schema definitions.
	 *
	 * Usage:
	 *   $schema = $totalcms->schemaFetcher()->fetchSchema('blog');
	 */
	public function schemaFetcher(): SchemaFetcher
	{
		return $this->container->get(SchemaFetcher::class);
	}

	/**
	 * Get the schema lister for listing available schemas.
	 *
	 * Usage:
	 *   $schemas = $totalcms->schemaLister()->listSchemas();
	 */
	public function schemaLister(): SchemaLister
	{
		return $this->container->get(SchemaLister::class);
	}

	/**
	 * Get the index builder for rebuilding collection indexes.
	 *
	 * Usage:
	 *   $totalcms->indexBuilder()->rebuildIndex('blog');
	 */
	public function indexBuilder(): IndexBuilder
	{
		return $this->container->get(IndexBuilder::class);
	}

	/**
	 * Get the file saver for programmatically saving files.
	 *
	 * Usage:
	 *   $totalcms->fileSaver()->saveFile('documents', 'doc-id', 'file', $uploadedFile);
	 */
	public function fileSaver(): FileSaver
	{
		return $this->container->get(FileSaver::class);
	}

	/**
	 * Get the image saver for programmatically saving images.
	 *
	 * Usage:
	 *   $totalcms->imageSaver()->saveImage('gallery', 'gallery-id', 'image', $uploadedFile);
	 */
	public function imageSaver(): ImageSaver
	{
		return $this->container->get(ImageSaver::class);
	}

	public function cacheManager(): CacheManager
	{
		return $this->cacheManager;
	}

	public function licenseValidator(): LicenseValidator
	{
		return $this->container->get(LicenseValidator::class);
	}

	public function editionFeatures(): EditionFeatureService
	{
		return $this->container->get(EditionFeatureService::class);
	}

	public function jumpStartExporter(): JumpStartExporter
	{
		return $this->container->get(JumpStartExporter::class);
	}

	public function jumpStartImporter(): JumpStartImporter
	{
		return $this->container->get(JumpStartImporter::class);
	}

	public function syncService(): SyncService
	{
		return $this->container->get(SyncService::class);
	}

	public function updateChecker(): UpdateChecker
	{
		return $this->container->get(UpdateChecker::class);
	}

	public function updateDownloader(): UpdateDownloader
	{
		return $this->container->get(UpdateDownloader::class);
	}

	public function updateApplier(): UpdateApplier
	{
		return $this->container->get(UpdateApplier::class);
	}

	public function collectionZipper(): CollectionZipper
	{
		return $this->container->get(CollectionZipper::class);
	}

	public function objectZipper(): ObjectZipper
	{
		return $this->container->get(ObjectZipper::class);
	}

	public function jsonImporter(): JsonImporter
	{
		return $this->container->get(JsonImporter::class);
	}

	public function csvImporter(): CsvImporter
	{
		return $this->container->get(CsvImporter::class);
	}

	public function deckJsonImporter(): DeckJsonImporter
	{
		return $this->container->get(DeckJsonImporter::class);
	}

	public function deckCsvImporter(): DeckCsvImporter
	{
		return $this->container->get(DeckCsvImporter::class);
	}

	public function schemaSaver(): SchemaSaver
	{
		return $this->container->get(SchemaSaver::class);
	}

	public function indexQueryService(): IndexQueryService
	{
		return $this->container->get(IndexQueryService::class);
	}

	/**
	 * Create a logger for custom scripts.
	 *
	 * Logs will appear in the Log Analyzer in the admin dashboard.
	 *
	 * @param string $name The logger name (used as channel and filename)
	 * @param bool $console Also output to console (useful for CLI scripts)
	 * @param Level|null $level Log level (null uses system default)
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function createLogger(string $name, bool $console = false, ?Level $level = null): LoggerInterface
	{
		$loggerFactory = $this->container->get(LoggerFactory::class);

		$loggerFactory->addFileHandler($name . '.log', level: $level);

		if ($console) {
			$consoleLevel = ($level === Level::Debug) ? Level::Debug : Level::Info;
			$loggerFactory->addConsoleHandler($consoleLevel);
		}

		return $loggerFactory->createLogger($name);
	}

	// ---------------------------------------------------------------------------------
	// Public methods for page access
	// ---------------------------------------------------------------------------------

	/**
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 *
	 * @param string|array<string> $groups
	 */
	public function restrictPageAccess(array|string $groups = [], string $collection = '', ?string $customLoginUrl = null): void
	{
		$restricted = $this->access->restrictPageAccess($groups, $collection, $customLoginUrl);
		if ($restricted) {
			$this->endBuffer();
			exit(0);
		}
	}

	public function isUserLoggedIn(string $collection = ''): bool
	{
		return $this->access->userLoggedIn($collection);
	}

	/** @return array<string,mixed> */
	public function userData(): array
	{
		return $this->access->userData();
	}

	/**
	 * Disable browser caching for authenticated users.
	 * Call this at the top of pages that should not be cached when a user is logged in.
	 * This prevents stale cached pages from being shown after logout.
	 */
	public function noCacheIfAuthenticated(string $collection = ''): void
	{
		if ($this->access->userLoggedIn($collection)) {
			header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
		}
	}

	// ---------------------------------------------------------------------------------
	// Public methods for buffer control and Twig rendering
	// ---------------------------------------------------------------------------------

	public function startBuffer(): void
	{
		$this->buffer->start();
	}

	public function endBuffer(): void
	{
		$this->buffer->end();
	}

	/**
	 * Clear all caches.
	 *
	 * @return array<string,array<string,mixed>> Results per cache backend
	 */
	public function clearCache(): array
	{
		return $this->cacheManager->clearAllCaches();
	}

	/**
	 * Disable cache reads for the current process.
	 * Useful for CLI scripts that need fresh data on every read.
	 * This is in-memory only and does not affect other processes (e.g., web server).
	 *
	 * Note: Cache writes still occur to warm shared caches with fresh data.
	 */
	public function disableCache(): void
	{
		$this->cacheManager->disableCache();
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<mixed> $data
	 */
	public function processBufferMacros(array $data = [], bool $restartBuffer = false): string
	{
		$content = $restartBuffer ? $this->buffer->get() : $this->buffer->end();

		try {
			return $this->twigEngine->renderString($content, $data);
		} catch (\Throwable $th) {
			$scriptName  = $_SERVER['SCRIPT_NAME'] ?? '';
			$debuggerUrl = $this->config->api . '/admin/utils/twig-debugger?filepath=' . $scriptName;
			$error       = sprintf('Twig Error: %s <a href="%s">Check in Debugger</a>', $th->getMessage(), $debuggerUrl);
			$error       = HTMLUtils::element('p', $error, ['class' => 'cms-twig-error']);

			$this->logger->error(sprintf('%s: %s', $error, $th->getTraceAsString()));

			$content = str_contains($content, '<body>') ? str_replace('<body>', '<body>' . $error, $content) : $error . $content;
		}

		return $content;
	}

	/** @param array<mixed> $data */
	public function processMacros(string $templateName, array $data = []): string
	{
		try {
			return $this->twigEngine->render($templateName, $data);
		} catch (\Throwable $th) {
			$error = sprintf('processMacros: %s: %s', $th->getMessage(), $th->getTraceAsString());
			$this->logger->error($error);

			return '';
		}
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public static function isPreview(): bool
	{
		// Stacks internal PHP server
		$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');

		return $environment === 'preview' || PHP_SAPI === 'cli-server';
	}

	/** @param array<string,string> $options */
	public function sitemapForCollection(string $collection, array $options = []): string
	{
		$sitemapBuilder = $this->container->get(SitemapBuilder::class);

		return $sitemapBuilder->buildSitemap($collection, $options);
	}

	/**
	 * Create a PSR-7 response for sitemap output.
	 *
	 * @param array<string,string> $options
	 */
	public function createSitemapResponse(string $collection, array $options = []): ResponseInterface
	{
		$responseFactory = $this->container->get(ResponseFactoryInterface::class);
		$content         = $this->sitemapForCollection($collection, $options);

		$response = $responseFactory->createResponse(200);
		$response->getBody()->write($content);

		return $response
			->withHeader('Content-Type', 'application/xml; charset=utf-8')
			->withHeader('Cache-Control', 'public, max-age=86400');
	}

	/**
	 * @deprecated Use createSitemapResponse() instead. This method may be removed in future versions.
	 * The exit is here because this object is used in the CLI context, where the response is not returned.
	 *
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 *
	 * @param array<string,string> $options
	 * */
	public function outputSitemapForCollection(string $collection, array $options = []): void
	{
		$this->buffer->end();

		// Output the sitemap
		header('Content-Type: application/xml; charset=utf-8');
		header('Cache-Control: public, max-age=86400');

		echo $this->sitemapForCollection($collection, $options);

		exit(0);
	}

	// ---------------------------------------------------------------------------------
	// Public methods for file and depot path generation
	// ---------------------------------------------------------------------------------

	/**
	 * Get the filesystem path for a file property.
	 *
	 * @param array<string,string> $options
	 */
	public function filePath(string $id, array $options = []): ?string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';

		try {
			$fileFetcher = $this->container->get(Domain\Property\Service\FileFetcher::class);
			$file        = $fileFetcher->fetchFile($collection, $id, $property);

			$relativePath = Infrastructure\Filesystem\PathUtils::buildPath(
				$collection,
				$id,
				$property,
				$file->name
			);

			$dataDir = $this->config->datadir;

			return $dataDir . '/' . $relativePath;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Get the filesystem path for a depot file.
	 *
	 * @param array<string,string> $options
	 */
	public function depotPath(string $id, string $filePath, array $options = []): ?string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';

		// Handle full path in filePath parameter
		$subpath  = '';
		$filename = $filePath;
		if (str_contains($filePath, '/')) {
			$pathinfo = pathinfo($filePath);
			$subpath  = $pathinfo['dirname'];
			$filename = $pathinfo['basename'];
		}

		try {
			$relativePath = Infrastructure\Filesystem\PathUtils::buildPath(
				$collection,
				$id,
				$property,
				$filename,
				$subpath
			);

			$dataDir  = $this->config->datadir;
			$fullPath = $dataDir . '/' . $relativePath;

			// Check if file exists before returning path
			if (file_exists($fullPath)) {
				return $fullPath;
			}

			return null;
		} catch (\Throwable) {
			return null;
		}
	}
}
