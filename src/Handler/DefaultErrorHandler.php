<?php

namespace TotalCMS\Handler;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Default Error Renderer.
 */
readonly class DefaultErrorHandler
{
	private LoggerInterface $logger;

	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param ResponseFactoryInterface $responseFactory The response factory
	 * @param LoggerFactory $loggerFactory The logger factory
	 * @param OPcacheService $opcacheService The OPcache service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
		LoggerFactory $loggerFactory,
		private OPcacheService $opcacheService,
	) {
		$this->logger          = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('totalcms');
	}

	/**
	 * Invoke.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param \Throwable $exception The exception
	 * @param bool $displayErrorDetails Show error details
	 * @param bool $logErrors Log errors
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		\Throwable $exception,
		bool $displayErrorDetails,
		bool $logErrors,
	): ResponseInterface {
		// Clear OPcache to prevent cached errors from persisting
		// This ensures that after fixing code errors, the fixes take effect immediately
		if ($this->opcacheService->isAvailable()) {
			$this->opcacheService->clear();
		}

		// Log error
		if ($logErrors) {
			$this->logger->error(
				sprintf(
					'Error: Method: %s, Path: %s, %s',
					$request->getMethod(),
					$request->getUri()->getPath(),
					$this->getExceptionText($exception, 0, true),
				)
			);
		}

		// Integrate with Sentry
		\Sentry\captureException($exception);

		// Detect status code
		$statusCode = $this->getHttpStatusCode($exception);

		// Error message
		$errorMessage = $this->getErrorMessage($exception, $statusCode, true);

		// Render response with no-cache headers to prevent browser caching of errors
		$response = $this->responseFactory->createResponse();
		$response = $this->renderer->json($response, [
			'error' => [
				'message' => $errorMessage,
			],
		]);

		// Add no-cache headers for error responses to prevent browser/proxy caching
		$response = $response
			->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
			->withHeader('Pragma', 'no-cache')
			->withHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
			->withHeader('X-OPcache-Cleared', 'true')
			->withStatus($statusCode);

		return $response;
	}

	/**
	 * Get http status code.
	 *
	 * @param \Throwable $exception The exception
	 *
	 * @return int The http code
	 */
	private function getHttpStatusCode(\Throwable $exception): int
	{
		// Detect status code
		$statusCode = StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR;

		if ($exception instanceof HttpException) {
			$statusCode = (int)$exception->getCode();
		}

		if ($exception instanceof \DomainException || $exception instanceof \InvalidArgumentException) {
			// Bad request
			$statusCode = StatusCodeInterface::STATUS_BAD_REQUEST;
		}

		$file = basename($exception->getFile());
		if ($file === 'CallableResolver.php') {
			$statusCode = StatusCodeInterface::STATUS_NOT_FOUND;
		}

		return $statusCode;
	}

	/**
	 * Get error message.
	 *
	 * @param \Throwable $exception The error
	 * @param int $statusCode The http status code
	 * @param bool $displayErrorDetails Display details
	 *
	 * @return string The message
	 */
	private function getErrorMessage(\Throwable $exception, int $statusCode, bool $displayErrorDetails): string
	{
		$reasonPhrase = $this->responseFactory->createResponse()->withStatus($statusCode)->getReasonPhrase();
		$errorMessage = sprintf('%s %s', $statusCode, $reasonPhrase);

		if ($displayErrorDetails) {
			$errorMessage = sprintf(
				'%s - %s',
				$errorMessage,
				$this->getExceptionText($exception)
			);
		}

		return $errorMessage;
	}

	/**
	 * Get exception text.
	 *
	 * @param \Throwable $exception Error
	 * @param int $maxLength The max length of the error message
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @return string The full error message
	 */
	private function getExceptionText(\Throwable $exception, int $maxLength = 0, bool $backtrace = false): string
	{
		$code    = $exception->getCode();
		$file    = $exception->getFile();
		$line    = $exception->getLine();
		$message = $exception->getMessage();
		$trace   = $exception->getTraceAsString();
		$error   = $message;

		if ($backtrace) {
			$error = sprintf('[%s] %s in %s on line %s.', $code, $message, $file, $line);
			$error .= sprintf("\nBacktrace:\n%s", $trace);
		}

		if ($maxLength > 0) {
			$error = substr($error, 0, $maxLength);
		}

		return $error;
	}
}
