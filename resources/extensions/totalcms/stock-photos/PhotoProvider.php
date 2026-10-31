<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

/**
 * A source the panel can show as a tab: a stock photo service (Pixabay) or
 * one of the site's own galleries. The panel and core never see which one
 * answered; they get the same result shape from every provider.
 */
interface PhotoProvider
{
	/** Short id used in routes and cache keys, e.g. "pixabay". */
	public function id(): string;

	/** The name shown with results, as the provider's terms ask. */
	public function label(): string;

	/** The provider's home page, linked from the panel. */
	public function homeUrl(): string;

	/** The license photos are used under, linked from the panel so editors can read it. Empty for none. */
	public function licenseUrl(): string;

	/** Whether an empty search lists photos (a gallery) or needs a query first (a stock service). */
	public function browses(): bool;

	/**
	 * One page of results.
	 *
	 * @param string $orientation 'horizontal' or 'vertical'; anything else means all
	 *
	 * @throws \RuntimeException when the provider has no key or cannot be reached
	 *
	 * Each photo has id, thumb, preview, download, filename, width, height, alt
	 * and credit; a gallery photo also carries tags, link and focalpoint
	 *
	 * @return array{total: int, photos: list<array<string,mixed>>}
	 */
	public function search(string $query, int $page, string $orientation): array;

	/**
	 * Whether a URL is one of this provider's own image files. The download
	 * proxy fetches nothing else, so it cannot be used to reach other hosts.
	 */
	public function servesImage(string $url): bool;
}
