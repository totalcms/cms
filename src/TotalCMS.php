<?php

namespace TotalCMS;

use DI\Container;
use Odan\Session\PhpSession;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Twig\TwigCacheCleaner;
use TotalCMS\Domain\Twig\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Utils\HTMLUtils;

// ---------------------------------------------------------------------------------
// Entry point for Total CMS PHP API
// ---------------------------------------------------------------------------------
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
		$this->logger  = $loggerFactory->addFileHandler('totalcms-twig.log')->createLogger('totalcms-twig');

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

	/** @param string|array<string> $groups */
	public function restrictPageAccess(array|string $groups = [], string $collection = ''): void
	{
		$this->access->restrictPageAccess($groups, $collection);
	}

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
}
