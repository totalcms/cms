<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\MetaBuilder;
use TotalCMS\Domain\Seo\Service\SeoContextFactory;
use TotalCMS\Domain\Sitemap\Data\SitemapLocation;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

/**
 * The images and videos on an object's page, added to its sitemap entry so
 * search engines find what they cannot see in the HTML: gallery images a
 * lightbox loads, objects behind Load More, a video in a player.
 *
 * Only the properties the collection's sitemap settings name are listed.
 * Images go through ImageWorks, so they carry the schema's watermark and are
 * the image visitors see. The share card is never listed: it is not on the page.
 */
readonly class SitemapMedia
{
	/** Width of the listed images; ImageWorks never makes one larger than it is. */
	public const IMAGE_WIDTH = 1600;
	/** Google's limit per page. */
	public const MAX_IMAGES = 1000;
	/** Google reads at most this much of a video description. */
	private const DESCRIPTION_MAX = 2048;
	/** Providers whose embed URL is a real player; `unknown` is only the URL given. */
	private const NO_PLAYER = ['unknown', ''];

	public function __construct(
		private MediaTwigAdapter $media,
		private VideoUrlResolver $videoUrls,
		private SeoContextFactory $contexts,
		private MetaBuilder $metaBuilder,
	) {
	}

	/**
	 * @param array<string,mixed> $object The full object, not its index entry
	 * @param list<string> $images Image and gallery properties
	 * @param list<string> $videos Video properties
	 */
	public function add(SitemapLocation $location, array $object, string $collection, array $images, array $videos, SeoSettings $settings): void
	{
		$count = 0;
		foreach ($this->imageUrls($object, $collection, $images) as $url) {
			if ($count++ >= self::MAX_IMAGES) {
				break;
			}
			$location->addImage($settings->absolute($url));
		}

		$this->addVideos($location, $object, $collection, $videos, $settings);
	}

	/**
	 * @param array<string,mixed> $object
	 * @param list<string> $properties
	 *
	 * @return \Generator<string>
	 */
	private function imageUrls(array $object, string $collection, array $properties): \Generator
	{
		$params = ['w' => self::IMAGE_WIDTH];

		foreach ($properties as $property) {
			$options = ['collection' => $collection, 'property' => $property];
			$value   = $object[$property] ?? null;

			if (is_array($value) && array_is_list($value)) {
				foreach ($value as $image) {
					if (is_array($image) && self::isImage($image)) {
						yield $this->media->galleryPath($object, (string)$image['name'], $params, $options);
					}
				}

				continue;
			}

			if (is_array($value) && self::isImage($value)) {
				yield $this->media->imagePath($object, $params, $options);
			}
		}
	}

	/** @param array<array-key,mixed> $image */
	private static function isImage(array $image): bool
	{
		return (string)($image['name'] ?? '') !== '' && (int)($image['size'] ?? 0) > 0;
	}

	/**
	 * Google needs a thumbnail, a title and a description for every video.
	 * The title is the video's own, else the object's, else the page title;
	 * the description is the page's meta description, else the title.
	 *
	 * @param array<string,mixed> $object
	 * @param list<string> $properties
	 */
	private function addVideos(SitemapLocation $location, array $object, string $collection, array $properties, SeoSettings $settings): void
	{
		$meta = null;

		foreach ($properties as $property) {
			$video = new VideoData($object[$property] ?? null);
			if ($video->url() === '') {
				continue;
			}

			$info      = $this->videoUrls->resolve($video->url());
			$thumbnail = $settings->absolute($this->media->videoPoster($object, ['w' => 1280], ['collection' => $collection, 'property' => $property]));
			if ($thumbnail === '' || ($info->provider !== 'file' && in_array($info->provider, self::NO_PLAYER, true))) {
				continue;
			}

			$meta ??= $this->metaBuilder->build($this->contexts->make($object, ['collection' => $collection]));
			$title       = $video->title() !== '' ? $video->title() : ($meta->name !== '' ? $meta->name : $meta->title);
			$description = mb_substr($meta->description !== '' ? $meta->description : $title, 0, self::DESCRIPTION_MAX);
			if ($title === '') {
				continue;
			}

			if ($info->provider === 'file') {
				$location->addVideo($settings->absolute($video->url()), $thumbnail, $title, $description);
			} elseif ($info->embedUrl !== '') {
				$location->addHostedVideo($info->embedUrl, $thumbnail, $title, $description);
			}
		}
	}
}
