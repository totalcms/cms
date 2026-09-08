<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Video\Service\VideoMetadataFetcher;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

/**
 * Service for processing property data before save operations.
 *
 * This service handles business logic that was previously embedded
 * in property data classes, improving separation of concerns.
 */
class PropertyDataProcessor implements PropertyDataProcessorInterface
{
	public function __construct(
		private VideoUrlResolver $videoResolver,
		private VideoMetadataFetcher $videoFetcher,
	) {
	}

	/**
	 * Process property data before save operations.
	 */
	public function processBeforeSave(PropertyData $property): PropertyData
	{
		// Handle DateData specific processing
		if ($property instanceof DateData) {
			return $this->processDateData($property);
		}

		if ($property instanceof VideoData) {
			return $this->processVideoData($property);
		}

		if ($property instanceof ImageData) {
			return $this->processImageData($property);
		}

		if ($property instanceof GalleryData) {
			return $this->processGalleryData($property);
		}

		if ($property instanceof CardData) {
			return $this->processCardData($property);
		}

		if ($property instanceof DeckData) {
			return $this->processDeckData($property);
		}

		return $property;
	}

	/**
	 * A card stores its children as plain arrays; the ones whose field type
	 * needs save-time work (today: `video`, whose provider/thumbnail/title are
	 * derived from the URL) are re-hydrated, processed, and written back.
	 * Which children those are comes from the factory (CardData::$childTypes).
	 */
	private function processCardData(CardData $card): CardData
	{
		foreach ($card->childTypes as $name => $type) {
			if ($type !== 'video' || !array_key_exists($name, $card->card)) {
				continue;
			}
			$card->card[$name] = $this->processNestedVideo($card->card[$name], $card->childSettings[$name] ?? []);
		}

		return $card;
	}

	/** Same as processCardData(), for every item of a deck. */
	private function processDeckData(DeckData $deck): DeckData
	{
		foreach ($deck->childTypes as $name => $type) {
			if ($type !== 'video') {
				continue;
			}
			foreach ($deck->deck as $itemId => $item) {
				if (!array_key_exists($name, $item)) {
					continue;
				}
				$deck->deck[$itemId][$name] = $this->processNestedVideo($item[$name], $deck->childSettings[$name] ?? []);
			}
		}

		return $deck;
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private function processNestedVideo(mixed $raw, array $settings): array
	{
		return $this->processVideoData(new VideoData($raw, $settings))->transform();
	}

	/**
	 * Compute a fresh content hash so ImageWorks URLs bust cached crops.
	 */
	private function processImageData(ImageData $imageData): ImageData
	{
		$payload         = $imageData->transform();
		$imageData->hash = ImageHashService::compute($payload);

		return $imageData;
	}

	private function processGalleryData(GalleryData $galleryData): GalleryData
	{
		foreach ($galleryData->images as $image) {
			$this->processImageData($image);
		}

		return $galleryData;
	}

	/**
	 * Process DateData before save operations.
	 */
	private function processDateData(DateData $dateData): DateData
	{
		if (isset($dateData->settings[DateData::CREATION_DATE]) && $dateData->settings[DateData::CREATION_DATE] === true) {
			if ($dateData->date === '' || $dateData->date === DateData::CREATION_DATE) {
				$dateData->date = DateData::cleanDate();
			}
		} elseif (isset($dateData->settings[DateData::UPDATE_DATE]) && $dateData->settings[DateData::UPDATE_DATE] === true) {
			$dateData->date = DateData::cleanDate();
		}

		return $dateData;
	}

	/**
	 * Resolve the provider/videoId/aspectRatio derived from the video's
	 * `url`, fetch oEmbed metadata only when nothing is cached yet, enforce
	 * the field's `settings.providers` allow-list, and run any uploaded
	 * poster through the same hash step a top-level image gets.
	 *
	 * @throws \DomainException when `settings.providers` is set and the
	 *                           resolved provider isn't in it.
	 */
	private function processVideoData(VideoData $video): VideoData
	{
		$url        = trim($video->url);
		$video->url = $url;

		if ($url === '') {
			// Empty URL: clear every derived key, leave poster untouched.
			$video->clearDerived();

			return $video;
		}

		// Captured before withDerived() overwrites them, so we can tell a
		// changed URL (re-fetch) from a re-save of the same URL after a
		// previously failed fetch (don't hammer the provider every save).
		$storedProvider = $video->provider();
		$storedVideoId  = $video->videoId();

		$info = $this->videoResolver->resolve($url);

		$providers = $video->settings['providers'] ?? null;
		if (is_array($providers) && $providers !== [] && !in_array($info->provider, $providers, true)) {
			throw new \DomainException(sprintf(
				'Video URL is a %s link; this field accepts: %s',
				$info->provider,
				implode(', ', $providers),
			));
		}

		// Fetch only when we have nothing yet AND the URL actually changed
		// since the last resolve (or there was no previous resolve at all —
		// e.g. an API writer sending only `url`). A stored thumbnail means a
		// previous fetch already succeeded; a stored provider/videoId that
		// still matches means a previous fetch already ran (and failed) for
		// this exact URL — don't hit the network again on every subsequent
		// save of unrelated fields.
		$meta = [];
		$urlChanged = $storedProvider === '' || $storedProvider !== $info->provider || $storedVideoId !== $info->videoId;
		if ($video->thumbnail() === '' && !in_array($info->provider, ['file', 'unknown'], true) && $urlChanged) {
			$meta = $this->videoFetcher->fetch($info);
		}

		// provider/videoId always recomputed; thumbnail/title/aspectRatio
		// favor fetched metadata, fall back to what's already stored, then
		// to the resolver's own defaults (VideoData::withDerived()).
		$video->withDerived($info, $meta);

		if ($video->hasPoster()) {
			$posterSettings = $video->settings['poster'] ?? [];
			$poster         = new ImageData($video->poster, is_array($posterSettings) ? $posterSettings : []);
			$this->processImageData($poster);
			$video->poster = $poster->transform();
		}

		return $video;
	}
}
