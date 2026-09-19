<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * The resolved `<head>` meta values for one page or object.
 *
 * Values are raw — the template escapes them.
 */
final readonly class MetaPayload
{
	/**
	 * @param string $rawTitle The title before the site title template is applied (the template shapes only a title derived from the object's own `title`)
	 * @param string $socialTitle The share-card title: the card's Social Title, else $rawTitle; a derived title goes through the site's social title template
	 * @param string $socialDescription The share-card description: the card's Social Description, else the collection's mapped social description property, else $description
	 * @param string $contentType `website`, `article` or `blogposting` — the record's card, else the collection, else `website`
	 * @param string $ogType `website` or `article`, derived from $contentType
	 * @param string $publishedTime The object's `date`, else `created`, for `article:published_time`; `''` when not an article
	 * @param string $modifiedTime The object's `updated`, for `article:modified_time`; `''` when not an article
	 * @param string $robots `''`, `noindex`, `nofollow` or `noindex, nofollow`
	 * @param string $ogImage Absolute URL, or `''` when there is no image
	 * @param string $ogImageAlt The alt of that image, or `''` when it has none (or there is no image)
	 * @param string $metaTags The Site SEO record's Meta Tags, raw markup printed as written
	 */
	public function __construct(
		public string $title,
		public string $rawTitle,
		public string $socialTitle,
		public string $description,
		public string $socialDescription,
		public string $canonical,
		public string $robots,
		public string $contentType,
		public string $ogType,
		public string $publishedTime,
		public string $modifiedTime,
		public string $ogImage,
		public string $ogImageAlt,
		public string $twitterCard,
		public string $siteName,
		public string $twitterHandle,
		public string $metaTags,
		public bool $noindex,
	) {
	}
}
