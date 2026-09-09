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
	 * @param string $rawTitle The title before the site title template is applied
	 * @param string $robots `''`, `noindex`, `nofollow` or `noindex, nofollow`
	 * @param string $ogImage Absolute URL, or `''` when there is no image
	 * @param array{google:string,bing:string,pinterest:string} $verification
	 */
	public function __construct(
		public string $title,
		public string $rawTitle,
		public string $description,
		public string $canonical,
		public string $robots,
		public string $ogType,
		public string $ogImage,
		public string $twitterCard,
		public string $siteName,
		public string $twitterHandle,
		public array $verification,
		public bool $noindex,
	) {
	}
}
