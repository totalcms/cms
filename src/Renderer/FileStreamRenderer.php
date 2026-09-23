<?php

declare(strict_types=1);

namespace TotalCMS\Renderer;

use Nyholm\Psr7\Stream;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two ways a file leaves the server: as an attachment download, or as
 * an inline stream with byte-range support for media players.
 *
 * Both close the session first (so a long transfer holds no file lock — shared
 * hosts kill locked processes) and drop PHP's output buffers (which silently
 * truncate large binary streams). The range handling used to be copied
 * between StreamAction and StreamUploadAction.
 */
final readonly class FileStreamRenderer
{
	public function __construct(
		private PhpSession $session,
	) {
	}

	/**
	 * @param resource|string $body
	 */
	public function download(ResponseInterface $response, string $mime, string $filename, mixed $body): ResponseInterface
	{
		$this->releaseBeforeStreaming();

		return $response
			->withHeader('Content-Type', $mime)
			->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
			->withHeader('X-Accel-Buffering', 'no')
			->withBody(Stream::create($body));
	}

	/**
	 * @param \Closure(): (resource|string) $open  opens the file; called once, after the range is known
	 * @param int|null                      $mtime Last modification time. When known, Last-Modified and an
	 *                                             ETag are sent — Safari uses them to confirm the chunks it
	 *                                             seeks through belong to one file revision, and abandons
	 *                                             playback of a non-faststart MP4 without them.
	 */
	public function stream(ServerRequestInterface $request, ResponseInterface $response, string $mime, string $filename, int $size, \Closure $open, ?int $mtime = null): ResponseInterface
	{
		$this->releaseBeforeStreaming();

		$response = $response
			->withHeader('Content-Type', $mime)
			->withHeader('Content-Disposition', "inline; filename=\"{$filename}\"")
			->withHeader('Accept-Ranges', 'bytes')
			->withHeader('Cache-Control', 'no-cache');

		if ($mtime !== null) {
			$response = $response
				->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT')
				->withHeader('ETag', '"' . dechex($mtime) . '-' . dechex($size) . '"');
		}

		$rangeHeader = $request->getHeaderLine('Range');
		if ($rangeHeader !== '' && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
			$start = (int)$matches[1];
			$end   = $matches[2] === '' ? $size - 1 : (int)$matches[2];

			if ($start >= $size || $end >= $size || $start > $end) {
				return $response->withStatus(416)->withHeader('Content-Range', "bytes */{$size}");
			}

			$length  = $end - $start + 1;
			$content = '';
			$stream  = $open();
			if (is_resource($stream) && $length > 0) {
				fseek($stream, $start);
				$read    = fread($stream, $length);
				$content = $read !== false ? $read : '';
				fclose($stream);
			}

			// No X-Accel-Buffering on a 206: the content is already in memory,
			// and the header made nginx/PHP-FPM switch to chunked transfer
			// encoding, which strips Content-Length — and Safari refuses a 206
			// without one.
			return $response
				->withStatus(206)
				->withHeader('Content-Length', (string)$length)
				->withHeader('Content-Range', "bytes {$start}-{$end}/{$size}")
				->withBody(Stream::create($content));
		}

		// The full file keeps X-Accel-Buffering off so nginx streams it without
		// loading the whole thing into memory.
		return $response
			->withHeader('X-Accel-Buffering', 'no')
			->withHeader('Content-Length', (string)$size)
			->withBody(Stream::create($open()));
	}

	private function releaseBeforeStreaming(): void
	{
		$this->session->save();

		// Output buffers are a web-SAPI concern: there is nothing to stream to
		// from the command line, and the test runner keeps a buffer of its own
		// that must be left alone.
		if (PHP_SAPI === 'cli') {
			return;
		}

		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}
}
