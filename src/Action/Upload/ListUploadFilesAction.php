<?php

namespace TotalCMS\Action\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

readonly class ListUploadFilesAction
{
	private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico', 'tiff', 'tif'];
	private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'avi', 'mkv', 'm4v'];

	public function __construct(
		private JsonRenderer $renderer,
		private UploadFetcher $fetcher,
		private Config $config,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];

		$files   = $this->fetcher->listFiles($collection, $id, $property);
		$apiPath = parse_url($this->config->api, PHP_URL_PATH) ?: $this->config->api;

		$queryParams = $request->getQueryParams();
		$preset      = $queryParams['preset'] ?? null;
		$type        = $queryParams['type'] ?? null;

		// Filter files by type if requested
		if (is_string($type) && $type !== '') {
			$files = array_values(array_filter($files, fn (array $file): bool => $this->matchesType($file['name'], $type)));
		}

		$result = [];
		foreach ($files as $file) {
			$path = $file['path'];
			$name = $file['name'];
			$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

			$isImage = in_array($ext, self::IMAGE_EXTENSIONS, true);

			if ($isImage) {
				$thumbnail = $apiPath . '/imageworks/upload/' . $path . '?w=200&h=200&fit=crop';
				$url       = $apiPath . '/imageworks/upload/' . $path;

				if (is_string($preset) && $preset !== '') {
					$url .= '?p=' . urlencode($preset);
				}
			} else {
				// Non-image files use the plain upload path
				$thumbnail = null;
				$url       = $apiPath . '/upload/' . $path;
			}

			$result[] = [
				'name'      => $name,
				'thumbnail' => $thumbnail,
				'url'       => $url,
			];
		}

		return $this->renderer->json($response, ['files' => $result]);
	}

	private function matchesType(string $filename, string $type): bool
	{
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		return match ($type) {
			'image' => in_array($ext, self::IMAGE_EXTENSIONS, true),
			'video' => in_array($ext, self::VIDEO_EXTENSIONS, true),
			'file'  => !in_array($ext, self::IMAGE_EXTENSIONS, true) && !in_array($ext, self::VIDEO_EXTENSIONS, true),
			default => true,
		};
	}
}
