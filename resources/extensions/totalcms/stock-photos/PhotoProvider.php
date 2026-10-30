<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

/**
 * A stock photo service the search panel can show. Pixabay is the only one
 * today; Pexels joins if it issues keys again. The panel and core never see
 * which one answered — they get the same result shape from every provider.
 */
interface PhotoProvider
{
	/** Short id used in routes and cache keys, e.g. "pixabay". */
	public function id(): string;

	/** The name shown with results, as the provider's terms ask. */
	public function label(): string;

	/** The provider's home page, linked from the panel. */
	public function homeUrl(): string;

	/** The license photos are used under, linked from the panel so editors can read it. */
	public function licenseUrl(): string;

	/**
	 * One page of results.
	 *
	 * @param string $orientation 'horizontal' or 'vertical'; anything else means all
	 *
	 * @throws \RuntimeException when the provider has no key or cannot be reached
	 *
	 * @return array{total: int, photos: list<array{id: string, thumb: string, preview: string, download: string, filename: string, width: int, height: int, alt: string, credit: array<string,string>}>}
	 */
	public function search(string $query, int $page, string $orientation): array;

	/**
	 * Whether a URL is one of this provider's own image files. The download
	 * proxy fetches nothing else, so it cannot be used to reach other hosts.
	 */
	public function servesImage(string $url): bool;
}
