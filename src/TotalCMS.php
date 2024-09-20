<?php

namespace TotalCMS;

use DI\Container;
use Odan\Session\PhpSession;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Buffer\BufferController;
use TotalCMS\Domain\Twig\TwigCacheCleaner;
use TotalCMS\Domain\Twig\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
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
	private Config $config;
	private UserValidationService $userValidator;

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
			$this->config           = $this->container->get(Config::class);
			$this->userValidator    = $this->container->get(UserValidationService::class);
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}
		if (!self::isPreview()) {
			$this->session->start();
		}
	}

	/**
	 *  @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @param string|array<string> $groups
	 */
	public function restrictPageAccess(array|string $groups = [], string $collection = ''): void
	{
		if (!$this->userHasAccess($groups, $collection)) {
			$this->session->set('requestOriginUrl', $_SERVER['REQUEST_URI']);
			$this->redirectToLogin($collection);
		}
	}

	/** @param string|array<string> $groups */
	public function userHasAccess(array|string $groups, string $collection = ''): bool
	{
		if (empty($groups)) {
			return $this->userLoggedIn($collection);
		}

		if (is_string($groups)) {
			$groups = [$groups];
		}

		if (!$this->session->has('user')) {
			return false;
		}

		try {
			$userID = $this->session->get('user');
			if ($this->userValidator->validateUserInGroups($userID, $groups, $collection)) {
				return true;
			}
		} catch (\Throwable $th) {
			// Current session user could be in a different user collection
			$this->session->delete('user');
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}

		return false;
	}

	public function userLoggedIn(string $collection = ''): bool
	{
		if (!$this->session->has('user')) {
			return false;
		}

		try {
			$userID = $this->session->get('user');
			if ($this->userValidator->validateUserById($userID, $collection)) {
				return true;
			}
		} catch (\Throwable $th) {
			// Current session user could be in a different user collection
			$this->session->delete('user');
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}

		return false;
	}

	private function redirectToLogin(string $collection = ''): void
	{
		$loginUrl = $this->config->api . '/login';
		if (!empty($collection)) {
			$loginUrl .= "/$collection";
		}
		header("Location: $loginUrl");
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

	/** @SuppressWarnings(PHPMD.Superglobals) */
	public static function isPreview(): bool
	{
		// Stacks internal PHP server
		$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
		$preview     = ($environment === 'preview' || PHP_SAPI === 'cli-server');
		return $preview;
	}
}
