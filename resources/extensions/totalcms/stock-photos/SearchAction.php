<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\JsonRenderer;

/**
 * GET /admin/ext/totalcms/stock-photos/search?q=&page=&orientation=
 *
 * The panel's only way to the provider: the API key stays on the server. Each
 * photo's `download` is rewritten to this extension's download proxy, so the
 * browser fetches the file same-origin.
 */
final readonly class SearchAction
{
	public function __construct(
		private PhotoProvider $provider,
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

		$data = [
			'provider'    => ['id' => $this->provider->id(), 'label' => $this->provider->label(), 'url' => $this->provider->homeUrl(), 'license' => $this->provider->licenseUrl()],
			'query'       => $query,
			'page'        => $page,
			'orientation' => $orientation,
			'total'       => 0,
			'photos'      => [],
		];

		if ($query === '') {
			return $this->renderer->json($response, $data);
		}

		try {
			$result = $this->provider->search($query, $page, $orientation);
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response->withStatus(502), $data + ['error' => $e->getMessage()]);
		}

		$proxy = (string)preg_replace('#/search$#', '/download', $request->getUri()->getPath());
		foreach ($result['photos'] as $i => $photo) {
			$result['photos'][$i]['download'] = $proxy . '?' . http_build_query(['url' => $photo['download']]);
		}

		return $this->renderer->json($response, ['total' => $result['total'], 'photos' => $result['photos']] + $data);
	}
}
