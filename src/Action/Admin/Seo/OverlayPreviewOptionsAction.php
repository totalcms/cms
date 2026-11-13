<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Seo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\MediaToolAccess;
use TotalCMS\Domain\Seo\Service\OverlayPreviewOptions;
use TotalCMS\Renderer\JsonRenderer;

/**
 * `GET /admin/seo/overlay-preview/options?collection=&q=` — what the overlay
 * designer's record picker and presets need: the collections the user may
 * list, the records they may read in one of them (newest first, searched
 * with `q`), a default record, whether that collection has its own overlay,
 * and the presets.
 */
readonly class OverlayPreviewOptionsAction
{
	public function __construct(
		private OverlayPreviewOptions $options,
		private MediaToolAccess $access,
		private JsonRenderer $jsonRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$query      = $request->getQueryParams();
		$collection = is_string($query['collection'] ?? null) ? $query['collection'] : '';
		$search     = is_string($query['q'] ?? null) ? $query['q'] : '';

		return $this->jsonRenderer->json($response, $this->options->for($this->access->actor(), $collection, $search))
			->withHeader('Cache-Control', 'no-store');
	}
}
