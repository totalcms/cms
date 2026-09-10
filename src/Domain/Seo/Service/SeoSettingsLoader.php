<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Support\Config;

/**
 * The single reader of the site-wide SEO record.
 *
 * Site SEO lives in the reserved `seo-site` singleton collection — one object,
 * stored under the collection's own id. This loader turns that record into a
 * SeoSettings: it survives a site that never provisioned the collection or
 * never saved the record, resolves the two image properties to absolute
 * ImageWorks URLs, and applies the site-name fallback chain.
 *
 * Memoised per instance — the container shares one loader per request, so a
 * page that renders `cms.seo.head()` and a sitemap in the same process read the
 * record once. Not `readonly` for that reason, and not `final` so the consumers'
 * unit tests can mock it.
 */
class SeoSettingsLoader
{
	/** Collection id, schema id and object id are all the same for the singleton. */
	private const RECORD = 'seo-site';

	private ?SeoSettings $loaded = null;

	public function __construct(
		private readonly CollectionFetcher $collections,
		private readonly ObjectFetcher $objects,
		private readonly MediaTwigAdapter $media,
		private readonly SettingsFetcher $settings,
		private readonly Config $config,
	) {
	}

	public function load(): SeoSettings
	{
		if ($this->loaded !== null) {
			return $this->loaded;
		}

		$record = $this->record();
		$domain = $this->config->domain;

		// Images are stored as image objects; SeoSettings wants absolute URLs.
		// The share image's alt travels with the image object, so read it before
		// the path resolution below replaces that object with a URL string.
		$record['defaultImageAlt'] = $this->imageAlt($record['defaultImage'] ?? null);

		// Resolve the ImageWorks paths first — that also replaces the arrays with
		// strings — then build the settings to learn the site's base URL, and
		// rebuild with each path absolutized against it.
		$record['defaultImage']     = $this->imagePath($record, 'defaultImage', SeoSettings::OG_IMAGE);
		$record['organizationLogo'] = $this->imagePath($record, 'organizationLogo', SeoSettings::LOGO_IMAGE);

		$settings                   = SeoSettings::fromArray($record, $domain);
		$record['defaultImage']     = $settings->absolute($record['defaultImage']);
		$record['organizationLogo'] = $settings->absolute($record['organizationLogo']);

		$settings = SeoSettings::fromArray($record, $domain);

		// Site SEO name → General settings site name → the site domain.
		if ($settings->siteName === '') {
			$general  = trim((string)($this->settings->loadSection('general')['siteName'] ?? ''));
			$settings = $settings->withSiteName($general !== '' ? $general : $domain);
		}

		return $this->loaded = $settings;
	}

	/**
	 * The singleton record, or an empty array when the collection was never
	 * provisioned or the operator has never saved the record.
	 *
	 * @return array<string,mixed>
	 */
	private function record(): array
	{
		if (!$this->collections->collectionExists(self::RECORD)) {
			return [];
		}

		try {
			return $this->objects->fetchObject(self::RECORD, self::RECORD)->toArray();
		} catch (\UnexpectedValueException) {
			// The collection exists but holds no record yet.
			return [];
		}
	}

	/**
	 * The `alt` off a raw image object, or `''` when the property holds no
	 * image object at all. An image with no alt is the same as no alt — the
	 * `og:image:alt` tag is simply omitted.
	 */
	private function imageAlt(mixed $image): string
	{
		return is_array($image) ? trim((string)($image['alt'] ?? '')) : '';
	}

	/**
	 * One image property as a root-relative ImageWorks path, or `''` when the
	 * property holds no image. `SeoSettings::absolute()` makes it absolute —
	 * social crawlers and JSON-LD need the host.
	 *
	 * The transform is the caller's: a share image is cropped to Open Graph's
	 * 1.91:1, a logo is only bounded in width so its aspect ratio survives.
	 *
	 * @param array<string,mixed>      $record
	 * @param array<string,int|string> $transform
	 */
	private function imagePath(array $record, string $property, array $transform): string
	{
		$image = $record[$property] ?? null;
		if (!is_array($image)) {
			return '';
		}

		$name = is_string($image['name'] ?? null) ? trim($image['name']) : '';
		if ($name === '' || (int)($image['size'] ?? 0) <= 0) {
			return '';
		}

		return $this->media->imagePath($record, $transform, ['collection' => self::RECORD, 'property' => $property]);
	}
}
