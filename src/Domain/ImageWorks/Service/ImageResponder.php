<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Renderer\ActiveContentHeaders;

/**
 * The HTTP side of an ImageWorks response: the original file served as is,
 * the long-lived cache headers, and a 304 when the browser already has it.
 */
final readonly class ImageResponder
{
	public function __construct(
		private StorageAdapterInterface $filesystem,
		private GlideFactory $glideFactory,
	) {
	}

	/** The source file itself, unprocessed. */
	public function original(string $imagePath, ?ServerRequestInterface $request = null): ResponseInterface
	{
		$response     = $this->glideFactory->originalImage($imagePath);
		$cacheHeaders = $this->cacheHeaders($imagePath, $request);

		if ($cacheHeaders['not_modified']) {
			return $this->notModified($cacheHeaders);
		}

		$mime = $response['mimeType'] ?: 'image/jpeg';

		// An SVG original can carry script; sandbox it when opened directly.
		return $this->withCacheHeaders(
			ActiveContentHeaders::apply(new Response(), $mime)
				->withHeader('Content-Type', $mime)
				->withHeader('Content-Length', (string)$this->filesystem->fileSize($imagePath)),
			$cacheHeaders,
		)->withBody($response['stream']);
	}

	/**
	 * A processed image: a 304 when the browser's copy is current, otherwise
	 * what `$render` returns, with the cache headers added. `$render` is only
	 * called when the image is actually sent.
	 *
	 * @param array<string,mixed> $params
	 * @param \Closure(): ResponseInterface $render
	 */
	public function processed(string $imagePath, ?ServerRequestInterface $request, array $params, \Closure $render): ResponseInterface
	{
		$cacheHeaders = $this->cacheHeaders($imagePath, $request, $params);

		if ($cacheHeaders['not_modified']) {
			return $this->notModified($cacheHeaders);
		}

		return $this->withCacheHeaders($render(), $cacheHeaders);
	}

	/**
	 * Cache headers for the image, and whether the request's conditional
	 * headers say the browser already has it.
	 *
	 * @param array<string,mixed> $params Params of a processed image, so each variant gets its own ETag
	 *
	 * @return array{etag: string, last_modified: string, cache_control: string, not_modified: bool}
	 */
	private function cacheHeaders(string $imagePath, ?ServerRequestInterface $request = null, array $params = []): array
	{
		// Try to get file modification time for ETag and Last-Modified
		try {
			$lastModified = $this->filesystem->flysystem()->lastModified($imagePath);
		} catch (\Exception) {
			$lastModified = time();
		}

		// Generate ETag from path, modification time, and params (for processed images)
		$etag        = '"' . md5($imagePath . $lastModified . serialize($params)) . '"';
		$notModified = false;

		// Check conditional headers if request is provided
		if ($request instanceof ServerRequestInterface) {
			// Check If-None-Match (ETag)
			if ($request->getHeaderLine('If-None-Match') === $etag) {
				$notModified = true;
			}

			// Check If-Modified-Since (Last-Modified)
			$ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
			if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $lastModified) {
				$notModified = true;
			}
		}

		return [
			'etag'          => $etag,
			'last_modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
			'cache_control' => 'public, max-age=31536000, immutable',
			'not_modified'  => $notModified,
		];
	}

	/** @param array{etag: string, cache_control: string} $cacheHeaders */
	private function notModified(array $cacheHeaders): ResponseInterface
	{
		return (new Response())
			->withStatus(304)
			->withHeader('Cache-Control', $cacheHeaders['cache_control'])
			->withHeader('ETag', $cacheHeaders['etag']);
	}

	/** @param array{etag: string, last_modified: string, cache_control: string} $cacheHeaders */
	private function withCacheHeaders(ResponseInterface $response, array $cacheHeaders): ResponseInterface
	{
		return $response
			->withHeader('Cache-Control', $cacheHeaders['cache_control'])
			->withHeader('ETag', $cacheHeaders['etag'])
			->withHeader('Last-Modified', $cacheHeaders['last_modified']);
	}
}
