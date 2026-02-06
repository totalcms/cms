<?php

namespace TotalCMS\Action\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

readonly class ListUploadFilesAction
{
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

		$result = [];
		foreach ($files as $file) {
			$path = $file['path'];
			$name = $file['name'];

			$thumbnail = $apiPath . '/imageworks/upload/' . $path . '?w=200&h=200&fit=crop';
			$url       = $apiPath . '/imageworks/upload/' . $path;

			if (is_string($preset) && $preset !== '') {
				$url .= '?p=' . urlencode($preset);
			}

			$result[] = [
				'name'      => $name,
				'thumbnail' => $thumbnail,
				'url'       => $url,
			];
		}

		return $this->renderer->json($response, ['files' => $result]);
	}
}
