<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Infrastructure\Filesystem\FileUtils;

/**
 * Guards against the PHP `post_max_size` overflow.
 *
 * When a request body is larger than `post_max_size`, PHP discards it *before*
 * any userland code runs: `$_POST` and `$_FILES` arrive empty, so upload
 * handlers report a misleading "No file found in request" 404. This middleware
 * detects the condition up front and returns a clear 413 Payload Too Large
 * instead, telling the operator which limit to raise.
 *
 * Note the leaked "PHP Request Startup: POST Content-Length ... exceeds the
 * limit" warning some see in dev is emitted during request startup, before this
 * (or any) code runs — it cannot be stripped here. It only reaches the response
 * body when `display_errors` is `1`; keep it out with `display_errors = stderr`
 * (or off), which is already the default outside dev.
 */
readonly class PostMaxSizeMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ResponseFactoryInterface $responseFactory,
		private JsonRenderer $renderer,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$postMax = FileUtils::iniSizeToBytes((string)ini_get('post_max_size'));

		if ($this->exceedsPostMax($request, $postMax)) {
			// With display_errors on (dev), PHP's "Request Startup: POST
			// Content-Length exceeds the limit" warning is emitted before any code
			// runs. If output buffering is active it's still in the buffer — drop
			// it so it can't corrupt the 413 body (and so it can't force
			// "headers already sent", which would defeat the 413 status). When
			// buffering is off the warning has already been flushed and cannot be
			// recovered here; keep it out of responses with display_errors=stderr
			// (or off) in the dev php.ini.
			if (ob_get_level() > 0) {
				ob_clean();
			}

			return $this->renderer->json(
				$this->responseFactory->createResponse(413),
				['error' => ['message' => sprintf(
					'Upload is too large — this server accepts at most %s per request. Increase post_max_size and upload_max_filesize in php.ini.',
					FileUtils::formatBytes($postMax, 1),
				)]],
			);
		}

		return $handler->handle($request);
	}

	/**
	 * True when the request body exceeded post_max_size and PHP dropped it.
	 * A too-large body leaves $_POST and $_FILES empty, so we require both the
	 * over-limit Content-Length AND the empty superglobals — that avoids
	 * false-positives from a spoofed Content-Length on a body PHP did parse.
	 */
	private function exceedsPostMax(ServerRequestInterface $request, int $postMax): bool
	{
		if ($postMax <= 0) {
			return false; // 0 = unlimited
		}

		if (!in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH'], true)) {
			return false;
		}

		$contentLength = (int)$request->getHeaderLine('Content-Length');

		return $contentLength > $postMax && $_POST === [] && $_FILES === [];
	}

}
