<?php

namespace TotalCMS;

use DI\Container;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

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
	private CacheManager $cacheManager;
	private PhpSession $session;
	private AccessManager $access;
	public Config $config;

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(bool $autoStartBuffer = true)
	{
		// Build PHP-DI Container instance
		$this->container = new Container(require __DIR__ . '/../config/container.php');

		$loggerFactory = $this->container->get(LoggerFactory::class);
		$this->logger  = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');

		// CLI mode, no need to start the session
		if (PHP_SAPI === 'cli') {
			return;
		}

		$preservedSessionData = [];
		$sessionStarted       = false;

		try {
			$this->buffer       = $this->container->get(BufferController::class);
			$this->twigEngine   = $this->container->get(TwigEngine::class);
			$this->cacheManager = $this->container->get(CacheManager::class);
			$this->config       = $this->container->get(Config::class);

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

	// ---------------------------------------------------------------------------------
	// Public methods for page access
	// ---------------------------------------------------------------------------------

	/**
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 *
	 * @param string|array<string> $groups
	 */
	public function restrictPageAccess(array|string $groups = [], string $collection = ''): void
	{
		$restricted = $this->access->restrictPageAccess($groups, $collection);
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

	public function clearCache(): void
	{
		$this->cacheManager->clearAllCaches();
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
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
