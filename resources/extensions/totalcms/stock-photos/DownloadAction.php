<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Support\RemoteFileDownloader;

/**
 * GET /admin/ext/totalcms/stock-photos/download?url=.
 *
 * Fetches one of the provider's own image files and hands the bytes back
 * same-origin, so the field can turn them into an upload without CORS. Only
 * a source's own image hosts are fetched (PhotoProvider::servesImage), through
 * the public-only client, under the site's download size cap.
 */
final readonly class DownloadAction
{
	private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

	public function __construct(
		private PhotoSources $sources,
		private RemoteFileDownloader $downloader,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$url      = (string)($request->getQueryParams()['url'] ?? '');
		$provider = $this->sources->servingImage($url);
		if ($provider === null) {
			return $this->fail($response, 400, 'Only stock photo images can be downloaded here.');
		}

		try {
			$path = $this->downloader->downloadPublic($url);
		} catch (\RuntimeException $e) {
			return $this->fail($response, 502, $e->getMessage());
		}

		$mime  = (string)mime_content_type($path);
		$bytes = (string)file_get_contents($path);
		@unlink($path);

		if (!in_array($mime, self::IMAGE_TYPES, true)) {
			return $this->fail($response, 502, $provider->label() . ' did not return an image.');
		}

		$response->getBody()->write($bytes);

		return $response
			->withHeader('Content-Type', $mime)
			->withHeader('Content-Length', (string)strlen($bytes))
			->withHeader('Cache-Control', 'private, no-store');
	}

	private function fail(ResponseInterface $response, int $status, string $message): ResponseInterface
	{
		$response->getBody()->write($message);

		return $response->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
	}
}
