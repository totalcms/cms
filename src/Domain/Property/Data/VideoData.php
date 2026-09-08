<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Domain\Video\Data\VideoInfo;

/**
 * Video property data — an external video: the author-supplied `url`, an
 * optional uploaded `poster` image, and a handful of provider-derived keys
 * (`provider`, `videoId`, `thumbnail`, `title`, `aspectRatio`) cached at
 * save time by the save pipeline (PropertyDataProcessor).
 *
 * A first-class value object, exactly like ImageData/FileData/ColorData: the
 * key set is fixed by this class, not by a user-editable sub-schema, so there
 * is no card and no card `id`. The stored shape is documented by
 * `resources/schemas/properties/video.json` and in
 * docs/planning/3.5.x/video-field.md ("Stored shape").
 */
class VideoData extends PropertyData implements \Stringable
{
	/**
	 * The stored keys, in stored order. `poster` is deliberately last and is
	 * only written when an image was actually uploaded. Used by the CSV
	 * exporter to build `{property}.{key}` columns without a schema lookup.
	 *
	 * @var list<string>
	 */
	public const KEYS = ['url', 'provider', 'videoId', 'thumbnail', 'title', 'aspectRatio', 'poster'];

	public const DEFAULT_ASPECT_RATIO = '16:9';

	public string $url;
	public string $provider;
	public string $videoId;
	public string $thumbnail;
	public string $title;
	public string $aspectRatio;
	/** @var array<string,mixed> Standard image-object shape, or [] when no poster was uploaded. */
	public array $poster;

	/**
	 * @param mixed               $value    The stored video array, or a JSON string of one
	 * @param array<string,mixed> $settings
	 */
	public function __construct(mixed $value = null, public array $settings = [])
	{
		$video = self::normalize($value);

		// Unknown keys — including the legacy card `id` that older records
		// carry — are simply not read.
		$this->url         = (string)($video['url'] ?? '');
		$this->provider    = (string)($video['provider'] ?? '');
		$this->videoId     = (string)($video['videoId'] ?? '');
		$this->thumbnail   = (string)($video['thumbnail'] ?? '');
		$this->title       = (string)($video['title'] ?? '');
		$this->aspectRatio = (string)($video['aspectRatio'] ?? self::DEFAULT_ASPECT_RATIO);
		$this->poster      = is_array($video['poster'] ?? null) ? $video['poster'] : [];
	}

	/**
	 * Accept the stored array, a JSON string of it (the admin form and CSV
	 * import both hand complex fields over as JSON), or nothing.
	 *
	 * @return array<string,mixed>
	 */
	private static function normalize(mixed $value): array
	{
		if (is_string($value)) {
			$decoded = json_decode($value, true);
			$value   = is_array($decoded) ? $decoded : [];
		}

		if (!is_array($value) || array_is_list($value)) {
			return [];
		}

		/** @var array<string,mixed> $value */
		return $value;
	}

	public function url(): string
	{
		return $this->url;
	}

	public function provider(): string
	{
		return $this->provider;
	}

	public function videoId(): string
	{
		return $this->videoId;
	}

	public function thumbnail(): string
	{
		return $this->thumbnail;
	}

	public function title(): string
	{
		return $this->title;
	}

	public function aspectRatio(): string
	{
		return $this->aspectRatio;
	}

	/** @return array<string,mixed> Standard image-object shape, or [] when no poster was uploaded. */
	public function poster(): array
	{
		return $this->poster;
	}

	/**
	 * True only for a genuinely uploaded poster. The admin field always
	 * round-trips a fully-shaped (but possibly empty) image object —
	 * `{name: '', size: 0, ...}` — through its hidden/sub-field inputs, so a
	 * bare non-empty-array check would treat "no poster" as "has a poster"
	 * and needlessly run it through the image-processing hash step.
	 */
	public function hasPoster(): bool
	{
		return (string)($this->poster['name'] ?? '') !== '' && (int)($this->poster['size'] ?? 0) > 0;
	}

	/**
	 * Set the derived keys from a resolve; fetched metadata wins, stored
	 * values survive a skipped fetch. `provider`/`videoId` are always
	 * recomputed from `$info`. For `thumbnail`/`aspectRatio`: a non-empty
	 * `$meta` value wins, else the value already stored here is kept when
	 * non-empty, else `$info`'s own value. For `title`: a non-empty `$meta`
	 * value wins, else the stored value is kept (there's no resolver-side
	 * title to fall back to).
	 *
	 * Passing `$meta = []` — the caller's signal that no fetch ran this
	 * save — is therefore safe to call unconditionally: it recomputes
	 * provider/videoId and leaves the rest exactly as they were.
	 *
	 * @param array<string,mixed> $meta Optional keys: thumbnail, title, aspectRatio
	 */
	public function withDerived(VideoInfo $info, array $meta): void
	{
		$this->provider = $info->provider;
		$this->videoId  = $info->videoId;

		$metaThumbnail   = (string)($meta['thumbnail'] ?? '');
		$this->thumbnail = $metaThumbnail !== '' ? $metaThumbnail : ($this->thumbnail !== '' ? $this->thumbnail : $info->thumbnail);

		$metaTitle   = (string)($meta['title'] ?? '');
		$this->title = $metaTitle !== '' ? $metaTitle : $this->title;

		$metaAspectRatio   = (string)($meta['aspectRatio'] ?? '');
		$this->aspectRatio = $metaAspectRatio !== '' ? $metaAspectRatio : ($this->aspectRatio !== '' ? $this->aspectRatio : $info->aspectRatio);
	}

	/** Reset every derived key to its empty default. `url` and `poster` are untouched. */
	public function clearDerived(): void
	{
		$this->provider    = '';
		$this->videoId     = '';
		$this->thumbnail   = '';
		$this->title       = '';
		$this->aspectRatio = self::DEFAULT_ASPECT_RATIO;
	}

	/**
	 * The six always-present keys, plus `poster` only when one was stored —
	 * an absent poster stays absent rather than persisting an empty object.
	 *
	 * @return array<string,mixed>
	 */
	public function transform(): array
	{
		$video = [
			'url'         => $this->url,
			'provider'    => $this->provider,
			'videoId'     => $this->videoId,
			'thumbnail'   => $this->thumbnail,
			'title'       => $this->title,
			'aspectRatio' => $this->aspectRatio,
		];

		// hasPoster(), not a bare non-empty check: the admin form round-trips a
		// fully-shaped empty image object (`{name: '', width: null, …}`) for a
		// poster that was never uploaded, and storing that fails schema
		// validation on the null integers.
		if ($this->hasPoster()) {
			$video['poster'] = $this->poster;
		}

		return $video;
	}

	/** What the search index and the CSV single-column fallback see. */
	public function __toString(): string
	{
		return trim($this->title . ' ' . $this->url);
	}
}
