<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\JsonRenderer;

/**
 * GET /admin/ext/totalcms/stock-photos/search?source=&q=&page=&orientation=.
 *
 * The panel's only way to a source: an API key stays on the server. A remote
 * photo's `download` is rewritten to this extension's download proxy, so the
 * browser fetches the file same-origin; a gallery photo already points at the
 * library route. `source` defaults to the first source the editor may use.
 */
final readonly class SearchAction
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
		$params      = $request->getQueryParams();
		$query       = trim((string)($params['q'] ?? ''));
		$page        = max(1, (int)($params['page'] ?? 1));
		$orientation = (string)($params['orientation'] ?? '');
		if (!in_array($orientation, ['all', 'horizontal', 'vertical'], true)) {
			$orientation = $this->defaultOrientation;
		}

		$sourceId = (string)($params['source'] ?? '');
		$provider = $sourceId === '' ? ($this->sources->available()[0] ?? null) : $this->sources->get($sourceId);
		if ($provider === null) {
			return $this->renderer->json($response->withStatus(404), ['error' => 'That photo source is not available.']);
		}

		$data = [
			'provider'    => self::describe($provider),
			'query'       => $query,
			'page'        => $page,
			'orientation' => $orientation,
			'total'       => 0,
			'photos'      => [],
		];

		if ($query === '' && !$provider->browses()) {
			return $this->renderer->json($response, $data);
		}

		try {
			$result = $provider->search($query, $page, $orientation);
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response->withStatus(502), $data + ['error' => $e->getMessage()]);
		}

		$proxy = (string)preg_replace('#/search$#', '/download', $request->getUri()->getPath());
		foreach ($result['photos'] as $i => $photo) {
			if ($provider->servesImage((string)$photo['download'])) {
				$result['photos'][$i]['download'] = $proxy . '?' . http_build_query(['url' => $photo['download']]);
			}
		}

		return $this->renderer->json($response, ['total' => $result['total'], 'photos' => $result['photos']] + $data);
	}

	/**
	 * What the panel needs to show a source as a tab.
	 *
	 * @return array{id: string, label: string, url: string, license: string, browses: bool}
	 */
	public static function describe(PhotoProvider $provider): array
	{
		return [
			'id'      => $provider->id(),
			'label'   => $provider->label(),
			'url'     => $provider->homeUrl(),
			'license' => $provider->licenseUrl(),
			'browses' => $provider->browses(),
		];
	}
}
