<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Support\HttpClientInterface;

/**
 * Pixabay (pixabay.com/api/docs). Its terms shape this class:
 * - search responses are cached for 24 hours, as the API docs require;
 * - preview URLs expire after a day, so nothing here is stored but the
 *   cached response — the image itself is downloaded when it is dropped;
 * - the download is the biggest file the key may have: `imageURL` (the
 *   original) or `fullHDURL` (1920px) appear only for keys Pixabay has
 *   approved for full API access; every key gets `largeImageURL` (1280px).
 *
 * The API has no alt text, so alt is built from the photo's tags.
 */
final readonly class PixabayProvider implements PhotoProvider
{
	public const PER_PAGE = 30;
	private const API     = 'https://pixabay.com/api/';
	private const CACHE   = 86400;

	public function __construct(
		private HttpClientInterface $http,
		private CacheManager $cache,
		private string $key,
		private bool $safeSearch = true,
	) {
	}

	public function id(): string
	{
		return 'pixabay';
	}

	public function label(): string
	{
		return 'Pixabay';
	}

	public function homeUrl(): string
	{
		return 'https://pixabay.com/';
	}

	public function licenseUrl(): string
	{
		return 'https://pixabay.com/service/license-summary/';
	}

	public function browses(): bool
	{
		return false;
	}

	public function search(string $query, int $page, string $orientation): array
	{
		if ($this->key === '') {
			throw new \RuntimeException('Add your Pixabay API key in the Stock Photos extension settings.');
		}

		$params = [
			'q'           => mb_substr(trim($query), 0, 100),
			'image_type'  => 'photo',
			'orientation' => in_array($orientation, ['horizontal', 'vertical'], true) ? $orientation : 'all',
			'safesearch'  => $this->safeSearch ? 'true' : 'false',
			'page'        => max(1, $page),
			'per_page'    => self::PER_PAGE,
		];

		$cacheKey = 'stock-photos-pixabay-' . md5((string)json_encode($params));
		$cached   = $this->cache->getData($cacheKey);
		if (is_array($cached) && isset($cached['total'], $cached['photos'])) {
			/** @var array{total: int, photos: list<array{id: string, thumb: string, preview: string, download: string, filename: string, width: int, height: int, alt: string, credit: array<string,string>}>} $cached */
			return $cached;
		}

		$response = $this->http->request('GET', self::API . '?' . http_build_query(['key' => $this->key] + $params), [
			'timeout' => 10,
			'headers' => ['Accept: application/json'],
		]);

		if (!$response->isSuccess()) {
			// Pixabay answers a bad key or a page past the last with a plain-text 400.
			throw new \RuntimeException(match ($response->statusCode) {
				400     => 'Pixabay refused the search: ' . mb_substr(trim(strip_tags($response->body)), 0, 120),
				429     => 'Pixabay rate limit reached. Try again in a minute.',
				default => 'Pixabay could not be reached (HTTP ' . $response->statusCode . ').',
			});
		}

		$result = $this->normalize($response->json() ?? []);
		$this->cache->storeData($cacheKey, $result, self::CACHE);

		return $result;
	}

	public function servesImage(string $url): bool
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));

		return ($parts['scheme'] ?? '') === 'https'
			&& ($host === 'pixabay.com' || str_ends_with($host, '.pixabay.com'));
	}

	/**
	 * @param array<mixed> $body
	 *
	 * @return array{total: int, photos: list<array{id: string, thumb: string, preview: string, download: string, filename: string, width: int, height: int, alt: string, credit: array<string,string>}>}
	 */
	private function normalize(array $body): array
	{
		$photos = [];
		foreach (is_array($body['hits'] ?? null) ? $body['hits'] : [] as $hit) {
			if (!is_array($hit) || !is_string($hit['largeImageURL'] ?? null)) {
				continue;
			}
			$id   = (string)($hit['id'] ?? '');
			$user = trim((string)($hit['user'] ?? ''));
			$tags = array_values(array_filter(array_map('trim', explode(',', (string)($hit['tags'] ?? '')))));

			$photos[] = [
				'id'       => $id,
				'thumb'    => (string)($hit['previewURL'] ?? ''),
				'preview'  => (string)($hit['webformatURL'] ?? ''),
				'download' => self::largest($hit),
				'filename' => self::filename($tags, $id),
				'width'    => (int)($hit['imageWidth'] ?? 0),
				'height'   => (int)($hit['imageHeight'] ?? 0),
				'alt'      => ucfirst(implode(', ', $tags)),
				'credit'   => array_filter([
					'author'    => $user,
					'authorUrl' => $user !== '' && isset($hit['user_id']) ? 'https://pixabay.com/users/' . rawurlencode($user) . '-' . (int)$hit['user_id'] . '/' : '',
					'source'    => 'Pixabay',
					'sourceUrl' => (string)($hit['pageURL'] ?? ''),
					'license'   => 'Pixabay Content License',
				], static fn (string $value): bool => $value !== ''),
			];
		}

		return ['total' => (int)($body['totalHits'] ?? 0), 'photos' => $photos];
	}

	/**
	 * The biggest image the key may download: the original or the 1920px
	 * version for full-access keys, else the 1280px one every key gets.
	 *
	 * @param array<mixed> $hit
	 */
	private static function largest(array $hit): string
	{
		foreach (['imageURL', 'fullHDURL', 'largeImageURL'] as $key) {
			if (is_string($hit[$key] ?? null) && $hit[$key] !== '') {
				return $hit[$key];
			}
		}

		return '';
	}

	/**
	 * A readable file name from the first tags, with the Pixabay id so the
	 * file can be traced for a takedown: "burrata-cheese-pixabay-12345".
	 *
	 * @param list<string> $tags
	 */
	private static function filename(array $tags, string $id): string
	{
		$words = strtolower(implode('-', array_slice($tags, 0, 3)));
		$slug  = trim((string)preg_replace('/[^a-z0-9]+/', '-', $words), '-');

		return ($slug !== '' ? $slug . '-' : '') . 'pixabay-' . $id;
	}
}
