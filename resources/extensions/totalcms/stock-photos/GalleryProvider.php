<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use TotalCMS\Domain\ImageWorks\Service\ImageworksUrl;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

/**
 * One of the site's own galleries, used as a photo library. A photo dropped
 * from it is copied into the field with its alt, credit, tags, link and focal
 * point; after that the copy is independent of the gallery.
 *
 * An empty search lists the whole gallery; otherwise every word must appear
 * in the image's alt, tags, file name or credit.
 */
final readonly class GalleryProvider implements PhotoProvider
{
	public const PER_PAGE   = 30;
	public const COLLECTION = 'gallery';
	public const PROPERTY   = 'gallery';

	/** The admin route that serves a gallery's original files, relative to the dashboard. */
	public const FILE_ROUTE = 'ext/totalcms/stock-photos/library';

	public function __construct(
		private string $galleryId,
		private PropertyFetcher $propertyFetcher,
		private string $api,
	) {
	}

	public function id(): string
	{
		return self::COLLECTION . ':' . $this->galleryId;
	}

	public function label(): string
	{
		return ucwords(str_replace(['-', '_'], ' ', $this->galleryId));
	}

	/** The gallery's page in the admin, relative to the dashboard. */
	public function homeUrl(): string
	{
		return 'collections/' . self::COLLECTION . '/' . rawurlencode($this->galleryId);
	}

	public function licenseUrl(): string
	{
		return '';
	}

	public function browses(): bool
	{
		return true;
	}

	public function galleryId(): string
	{
		return $this->galleryId;
	}

	public function search(string $query, int $page, string $orientation): array
	{
		$words   = preg_split('/\s+/', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$matches = array_values(array_filter(
			$this->images(),
			fn (ImageData $image): bool => $this->matches($image, $words) && $this->hasOrientation($image, $orientation),
		));

		$pageImages = array_slice($matches, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

		return [
			'total'  => count($matches),
			'photos' => array_map(fn (ImageData $image): array => $this->normalize($image), $pageImages),
		];
	}

	/** The gallery's own files are served by this extension's library route, never the proxy. */
	public function servesImage(string $url): bool
	{
		return false;
	}

	/** The stored image of that name, or null. */
	public function image(string $name): ?ImageData
	{
		foreach ($this->images() as $image) {
			if ($image->name === $name) {
				return $image;
			}
		}

		return null;
	}

	/** @return list<ImageData> */
	private function images(): array
	{
		try {
			$gallery = $this->propertyFetcher->fetchProperty(self::COLLECTION, $this->galleryId, self::PROPERTY);
		} catch (\Throwable) {
			throw new \RuntimeException("The gallery \"{$this->galleryId}\" could not be found.");
		}

		return $gallery instanceof GalleryData ? array_values($gallery->images) : [];
	}

	/** @param list<string> $words */
	private function matches(ImageData $image, array $words): bool
	{
		if ($words === []) {
			return true;
		}

		$haystack = mb_strtolower(implode(' ', [
			$image->alt,
			$image->name,
			implode(' ', $image->tags->transform()),
			(string)($image->credit['author'] ?? ''),
		]));

		foreach ($words as $word) {
			if (!str_contains($haystack, $word)) {
				return false;
			}
		}

		return true;
	}

	private function hasOrientation(ImageData $image, string $orientation): bool
	{
		return match ($orientation) {
			'horizontal' => $image->width >= $image->height,
			'vertical'   => $image->height > $image->width,
			default      => true,
		};
	}

	/** @return array<string,mixed> */
	private function normalize(ImageData $image): array
	{
		$stored = $image->transform();
		$thumb  = ImageworksUrl::buildImageworksGalleryAPI($this->api, $this->galleryId, $image->name, $stored, ['w' => 400, 'h' => 400, 'fit' => 'max']);

		return [
			'id'         => $image->name,
			'thumb'      => $thumb,
			'preview'    => $thumb,
			'download'   => self::FILE_ROUTE . '?' . http_build_query(['gallery' => $this->galleryId, 'name' => $image->name]),
			'filename'   => $image->name,
			'width'      => $image->width,
			'height'     => $image->height,
			'alt'        => $image->alt,
			'credit'     => $image->credit,
			'tags'       => $image->tags->transform(),
			'link'       => $image->link,
			'focalpoint' => $image->focalpoint,
		];
	}
}
