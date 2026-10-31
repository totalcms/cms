<?php

namespace TotalCMS\Handler;

use Fig\Http\Message\StatusCodeInterface;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\PathTraversalDetected;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Builder\Exception\PageNotFoundException;
use TotalCMS\Domain\Cache\Service\OPcacheService;
use TotalCMS\Domain\Template\Exception\TemplatesLockedException;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\PageRouterMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\RawRenderer;

use function Sentry\captureException;

/**
 * Default Error Renderer.
 *
 * Mapping exceptions to status codes means knowing each exception type, so
 * every one that earns its own status adds a dependency here.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
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
		private RawRenderer $rawRenderer,
	) {
		$this->logger          = $loggerFactory
			->channelLogger(LogChannel::App);
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
		$statusCode = $this->getHttpStatusCode($exception);

		// Reset OPcache after a fault in the code so that, once the file is
		// fixed, the fix takes effect immediately. Only a 5xx is a fault: a
		// routing 404 is the normal answer to a bot probing /wp-login.php, a
		// 400 or 403 is the request's fault (a refused path, a refused write),
		// and resetting the pool-wide bytecode cache on every such hit would
		// recompile the whole site each time.
		$opcacheCleared = $statusCode >= StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR
			&& $this->opcacheService->isAvailable()
			&& $this->opcacheService->clear();

		// Log error (skip expected 404s: HEAD existence checks, and requests the
		// PageRouterMiddleware may augment into a rendered builder page — every
		// builder-page view starts as a Slim routing 404. The page router logs
		// genuine misses itself at info level.)
		$isExpected404 = $exception instanceof HttpException
			&& $exception->getCode() === StatusCodeInterface::STATUS_NOT_FOUND
			&& (
				$request->getMethod() === 'HEAD'
				|| (bool)$request->getAttribute(PageRouterMiddleware::AUGMENTS_404)
			);

		if ($logErrors && !$isExpected404) {
			$this->logger->error(
				sprintf(
					'Error: Method: %s, Path: %s, %s',
					$request->getMethod(),
					$request->getUri()->getPath(),
					$this->getExceptionText($exception, 0, true),
				)
			);
		}

		// Integrate with Sentry (skip expected 404s)
		if (!$isExpected404) {
			captureException($exception);
		}

		// Error message
		$errorMessage = $this->getErrorMessage($exception, $statusCode, true);

		// Render response with no-cache headers to prevent browser caching of errors
		$response = $this->responseFactory->createResponse();

		// An htmx request swaps whatever comes back, error status or not, so a
		// JSON body would land in the page as text. Give it a fragment instead:
		// the same status, the same message, and for a validation failure one
		// <li> per field so a form can show inline errors.
		if ($request->getHeaderLine('HX-Request') === 'true') {
			$response = $this->rawRenderer->render(
				$response->withHeader('Content-Type', 'text/html'),
				$this->htmlFragment($statusCode, $errorMessage, $exception),
			);
		} else {
			$response = $this->renderer->json($response, [
				'error' => [
					'message' => $errorMessage,
				],
			]);
		}

		// Add no-cache headers for error responses to prevent browser/proxy caching
		$response = $response
			->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
			->withHeader('Pragma', 'no-cache')
			->withHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
			->withStatus($statusCode);

		return $opcacheCleared ? $response->withHeader('X-OPcache-Cleared', 'true') : $response;
	}

	/**
	 * The HTML error fragment for htmx callers.
	 */
	private function htmlFragment(int $statusCode, string $errorMessage, \Throwable $exception): string
	{
		$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		$fields = $this->validationFields($exception);

		$html = sprintf('<div class="cms-error cms-error-%d" role="alert">', $statusCode);
		if ($fields !== []) {
			$html .= '<p>Please fix the highlighted fields.</p><ul class="cms-error-fields">';
			foreach ($fields as $field => $message) {
				$html .= sprintf('<li data-field="%s">%s</li>', $escape($field), $escape($message));
			}
			$html .= '</ul>';
		} else {
			$html .= '<p>' . $escape($errorMessage) . '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * Field → message pairs from a SchemaValidator failure, whose message is
	 * `Schema Validation Failed. (/path) message;(/other) message`. A JSON
	 * pointer path becomes a dotted field name (`/cover/exif` → `cover.exif`).
	 *
	 * @return array<string,string>
	 */
	private function validationFields(\Throwable $exception): array
	{
		$message = $exception->getMessage();
		if (!str_starts_with($message, 'Schema Validation Failed.')) {
			return [];
		}

		$fields = [];
		preg_match_all('#\(/([^)]*)\)\s*([^;]+)#', $message, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$field          = trim(str_replace('/', '.', $match[1]));
			$fields[$field] = trim($match[2]);
		}

		return $fields;
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

		if ($exception instanceof \DomainException || $exception instanceof \InvalidArgumentException || $exception instanceof \UnexpectedValueException) {
			// Bad request
			$statusCode = StatusCodeInterface::STATUS_BAD_REQUEST;
		}

		if ($exception instanceof CorruptedPathDetected || $exception instanceof PathTraversalDetected) {
			// Flysystem refused a path built from the request (control
			// characters, `..`): the caller asked for a file that cannot exist.
			// Every download, stream and object route answered these with a
			// 500 (Schemathesis, 2026-10-30).
			$statusCode = StatusCodeInterface::STATUS_BAD_REQUEST;
		}

		if ($exception instanceof AccessDeniedException) {
			// The storage layer refused the current actor a write the entry
			// point let through (see ObjectWriteGate).
			$statusCode = StatusCodeInterface::STATUS_FORBIDDEN;
		}

		if ($exception instanceof TemplatesLockedException) {
			// Templates are git-managed on this environment — editing is forbidden.
			$statusCode = StatusCodeInterface::STATUS_FORBIDDEN;
		}

		if (PageNotFoundException::find($exception) instanceof PageNotFoundException) {
			// A template called cms.notFound() somewhere the page router did not
			// catch it (a render endpoint, the playground). Twig wraps it, so
			// look through the chain.
			$statusCode = StatusCodeInterface::STATUS_NOT_FOUND;
		}

		$file = basename($exception->getFile());
		if ($file === 'CallableResolver.php') {
			return StatusCodeInterface::STATUS_NOT_FOUND;
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
			return sprintf(
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
			return substr($error, 0, $maxLength);
		}

		return $error;
	}
}
