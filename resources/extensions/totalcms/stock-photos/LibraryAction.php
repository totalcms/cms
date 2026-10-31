<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * GET /admin/ext/totalcms/stock-photos/library?gallery=&name=.
 *
 * The original file of one image in a library gallery, so a dropped photo is
 * copied byte for byte rather than through ImageWorks (which would re-encode
 * it and could add a watermark). Only galleries chosen in the settings, and
 * only images the gallery lists, can be read here.
 */
final readonly class LibraryAction
{
	public function __construct(
		private PhotoSources $sources,
		private Config $config,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$params  = $request->getQueryParams();
		$gallery = $this->sources->gallery((string)($params['gallery'] ?? ''));
		$image   = $gallery?->image((string)($params['name'] ?? ''));
		if ($gallery === null || $image === null) {
			return $this->fail($response, 404, 'That image is not in a library gallery.');
		}

		$path = $this->config->datadir . '/' . PathUtils::buildPath(GalleryProvider::COLLECTION, $gallery->galleryId(), GalleryProvider::PROPERTY, basename($image->name));
		if (!is_file($path)) {
			return $this->fail($response, 404, 'The image file is missing.');
		}

		$bytes = (string)file_get_contents($path);
		$response->getBody()->write($bytes);

		return $response
			->withHeader('Content-Type', $image->mime !== '' ? $image->mime : (string)mime_content_type($path))
			->withHeader('Content-Length', (string)strlen($bytes))
			->withHeader('Cache-Control', 'private, no-store');
	}

	private function fail(ResponseInterface $response, int $status, string $message): ResponseInterface
	{
		$response->getBody()->write($message);

		return $response->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
	}
}
