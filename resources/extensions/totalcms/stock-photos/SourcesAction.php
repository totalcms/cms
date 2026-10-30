<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\JsonRenderer;

/**
 * GET /admin/ext/totalcms/stock-photos/sources
 *
 * The tabs the panel shows: every source the current editor may use.
 */
final readonly class SourcesAction
{
	public function __construct(
		private PhotoSources $sources,
		private JsonRenderer $renderer,
		private string $defaultOrientation = 'all',
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		return $this->renderer->json($response, [
			'orientation' => $this->defaultOrientation,
			'sources'     => array_map(fn (PhotoProvider $provider): array => SearchAction::describe($provider), $this->sources->available()),
		]);
	}
}
