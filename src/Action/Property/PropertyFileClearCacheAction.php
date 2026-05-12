<?php

namespace TotalCMS\Action\Property;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Traits\NestedPathDispatchTrait;

readonly class PropertyFileClearCacheAction
{
	use NestedPathDispatchTrait;

	public function __construct(
		private JsonRenderer $renderer,
		private PropertyCacheCleaner $service,
		private FileFetcher $fileFetcher,
	) {
	}

	/**
	 * Clear cache at `/{collection}/{id}/{property}/{path:.+}/cache`.
	 *
	 * The `path` segment is one of two things and the URL doesn't tell us which:
	 *   1. A filename inside a gallery property — clear `prop/.cache/{name}/`.
	 *   2. A child key inside a card-nested property — clear `prop/{key}/.cache/`.
	 *
	 * Dispatch on filesystem state via {@see NestedPathDispatchTrait}.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		['path' => $path, 'nested' => $nested] = $this->classifyDispatchPath($args, $this->fileFetcher);

		$deleted = $nested
			? $this->service->deletePropertyCache($args['collection'], $args['id'], $args['property'], $path)
			: $this->service->deleteFileCache($args['collection'], $args['id'], $args['property'], $path);

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
