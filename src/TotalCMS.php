<?php

namespace TotalCMS;

use DI\Container;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Sitemap\Service\SitemapBuilder;
use TotalCMS\Domain\Twig\TwigCacheCleaner;
use TotalCMS\Domain\Twig\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Utils\HTMLUtils;

/**
 * Entry point for Total CMS PHP API.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class TotalCMS
{
	private BufferController $buffer;
	private Container $container;
	private TwigEngine $twigEngine;
	private LoggerInterface $logger;
	private TwigCacheCleaner $twigCacheCleaner;
	private PhpSession $session;
	private AccessManager $access;

	public function __construct()
	{
		// Build PHP-DI Container instance
		$this->container = new Container(require __DIR__ . '/../config/container.php');

		$loggerFactory = $this->container->get(LoggerFactory::class);
		$this->logger  = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');

		// CLI mode, no need to start the session
		if (PHP_SAPI === 'cli') {
			return;
		}

		try {
			$this->buffer           = $this->container->get(BufferController::class);
			$this->twigEngine       = $this->container->get(TwigEngine::class);
			$this->twigCacheCleaner = $this->container->get(TwigCacheCleaner::class);
			$this->session          = $this->container->get(PhpSession::class);
			$this->access           = $this->container->get(AccessManager::class);
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}
		if (!self::isPreview()) {
			$this->session->start();
		}
	}

	/** @param array<string,mixed> $vars */
	public function restoreSessionVariables(array $vars): void
	{
		if (!$this->session->isStarted()) {
			return;
		}
		// Restore session variables
		// Only set the variables if they are not already set
		// This is to prevent overwriting existing session variables
		foreach ($vars as $key => $value) {
			if (!$this->session->has($key)) {
				$this->session->set($key, $value);
			}
		}
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

	/** @param string|array<string> $groups */
	public function restrictPageAccess(array|string $groups = [], string $collection = ''): void
	{
		$this->access->restrictPageAccess($groups, $collection);
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
		$this->twigCacheCleaner->deleteCache();
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param array<mixed> $data
	 */
	public function processBufferMacros(array $data = [], bool $restartBuffer = false): string
	{
		$content = $restartBuffer ? $this->buffer->get() : $this->buffer->end();

		try {
			return $this->twigEngine->renderString($content, $data);
		} catch (\Throwable $th) {
			$error = sprintf('processBufferMacros: %s', $th->getMessage());
			$error = HTMLUtils::element('p', $error, ['class' => 'cms-twig-error']);

			$this->logger->error(sprintf('%s: %s', $error, $th->getTraceAsString()));

			if (str_contains($content, '<body>')) {
				$content = str_replace('<body>', '<body>' . $error, $content);
			} else {
				$content = $error . $content;
			}
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
		$preview     = ($environment === 'preview' || PHP_SAPI === 'cli-server');

		return $preview;
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
}
