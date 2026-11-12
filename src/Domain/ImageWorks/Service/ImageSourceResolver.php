<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

/**
 * Finds the image an ImageWorks URL points at: an image property, a gallery
 * image, or an image nested in a card, deck or video. Throws
 * UnexpectedValueException when there is none.
 */
final readonly class ImageSourceResolver
{
	public function __construct(
		private PropertyFetcher $propertyFetcher,
	) {
	}

	public function image(string $collection, string $id, string $property): ImageData
	{
		$imageData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		if (!$imageData instanceof ImageData) {
			throw new \UnexpectedValueException('Invalid image property found');
		}

		return $imageData;
	}

	/**
	 * The image the `/{prop}/{path:.+}.{format}` route addresses, and the
	 * subpath its file lives under. One URL shape serves four property types,
	 * so dispatch on the resolved data shape:
	 *   - CardData    → `obj[prop][child]`         (path is `child`)
	 *   - DeckData    → `obj[prop][itemId][child]` (path is `itemId/child`)
	 *   - VideoData   → `obj[prop][poster]`        (path is always `poster`)
	 *   - GalleryData → the gallery image named `path` (no subpath).
	 *
	 * @return array{ImageData, ?string}
	 */
	public function pathImage(string $collection, string $id, string $property, string $path): array
	{
		$propertyData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		if ($propertyData instanceof CardData) {
			return [$this->nestedImage($propertyData->card, $property, $path), $path];
		}

		if ($propertyData instanceof DeckData) {
			return [$this->nestedImage($propertyData->deck, $property, $path), $path];
		}

		// A video's one nested image is its typed `poster` — hand it over in the
		// same `[childKey => imageRaw]` shape a card exposes so the lookup below
		// is shared. Any other path under a video is simply not found.
		if ($propertyData instanceof VideoData) {
			return [$this->nestedImage(['poster' => $propertyData->poster], $property, $path), $path];
		}

		if (!$propertyData instanceof GalleryData) {
			throw new \UnexpectedValueException('Invalid gallery property found');
		}

		return [$this->galleryImage($propertyData, $path), null];
	}

	/**
	 * The image a `markimage` layer names: an image property, or one gallery
	 * image by name or first/last. Null when there is none, or when it is
	 * another kind of field. A random or featured pick would freeze into the
	 * cached render, so those names are not offered.
	 */
	public function markImage(string $collection, string $id, string $property, ?string $name = null): ?ImageData
	{
		if (in_array($name, ['random', 'featured'], true)) {
			return null;
		}

		try {
			$propertyData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

			if ($name === null) {
				return $propertyData instanceof ImageData && $propertyData->name !== '' ? $propertyData : null;
			}

			return $propertyData instanceof GalleryData ? $this->galleryImage($propertyData, $name) : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/** Pick a gallery image by name (without extension) or by first/last/random/featured. */
	private function galleryImage(GalleryData $galleryData, string $name): ImageData
	{
		$images = $galleryData->images;

		if ($images === []) {
			throw new \UnexpectedValueException('Gallery has no images');
		}

		$imageData = match ($name) {
			'first'    => array_shift($images),
			'last'     => array_pop($images),
			'random'   => $this->randomImage($images),
			'featured' => $this->featuredImage($images),
			default    => $this->imageByName($images, $name),
		};

		if (empty($imageData)) {
			throw new \UnexpectedValueException('Gallery Image not found');
		}

		return $imageData;
	}

	/**
	 * An image stored as a child of a card or deck field. The `$path` is a
	 * slash-separated path through the parent property's nested data:
	 *   - Card child:   `path = "childKey"`        → `parentRaw[childKey]`
	 *   - Deck child:   `path = "itemId/childKey"` → `parentRaw[itemId][childKey]`.
	 *
	 * The disk file lives at `coll/id/property/<path>/<filename>`.
	 *
	 * @param array<int|string,mixed> $parentRaw
	 */
	private function nestedImage(array $parentRaw, string $property, string $path): ImageData
	{
		$segments = $path === '' ? [] : explode('/', $path);
		$cursor   = $parentRaw;
		foreach ($segments as $segment) {
			if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
				throw new \UnexpectedValueException("Nested image '{$path}' not found under '{$property}'");
			}
			$cursor = $cursor[$segment];
		}
		if (!is_array($cursor) || empty($cursor['name'])) {
			throw new \UnexpectedValueException("Nested image '{$path}' has no file");
		}

		return new ImageData($cursor);
	}

	/** @param array<ImageData> $images */
	private function imageByName(array $images, string $name): ?ImageData
	{
		$imageData = array_filter($images, fn (ImageData $image): bool => pathinfo($image->name)['filename'] === $name);

		if ($imageData === []) {
			return null;
		}

		return array_shift($imageData);
	}

	/** @param array<ImageData> $images */
	private function randomImage(array $images): ImageData
	{
		$count = count($images) - 1;

		if ($count === 0) {
			return $images[0];
		}

		return $images[mt_rand(0, $count)];
	}

	/** @param array<ImageData> $images */
	private function featuredImage(array $images): ImageData
	{
		$featured = array_filter($images, fn (ImageData $image): bool => $image->featured);
		$count    = count($featured);
		if ($count === 0) {
			// if no featured images are found, return a random image
			return $this->randomImage($images);
		}
		if ($count > 1) {
			shuffle($featured);
		}

		return array_shift($featured);
	}
}
