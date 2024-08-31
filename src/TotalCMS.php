<?php

namespace TotalCMS;

use DI\Container;
use Psr\Log\LoggerInterface;
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
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}
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
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<mixed> $data
	 */
	public function processBufferMacros(array $data = []): string
	{
		$content = $this->buffer->end();

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
}
