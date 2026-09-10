<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Everything the meta/JSON-LD builders need about "the thing being rendered",
 * resolved once by SeoContextFactory.
 *
 * The context is deliberately inert: image URLs are pre-resolved here so the
 * builders stay pure and never reach for the Twig media adapter, the storage
 * layer or the request.
 */
final readonly class SeoContext
{
	/**
	 * @param 'page'|'object'|'none' $kind
	 * @param array<string,mixed> $object The page record or collection object; `[]` for `none`
	 * @param string $collectionId `''` for `none`
	 * @param array{type:string,title:string,description:string,image:string} $seoBlock Collection-level property mapping
	 * @param string $siteName SEO site name → General `siteName` → domain
	 * @param string $url Absolute URL of the object/page; `''` when it cannot be resolved
	 * @param array<string,string> $imageUrls Absolute ImageWorks URLs keyed by property path (mapped field + `seo.image`)
	 * @param array<string,string> $imageAlts The alt text of those same images, under the same keys; a key is absent when the property holds no image
	 */
	public function __construct(
		public string $kind,
		public array $object,
		public string $collectionId,
		public ?CollectionData $collectionMeta,
		public array $seoBlock,
		public SeoFields $fields,
		public SeoSettings $settings,
		public string $siteName,
		public string $url,
		public array $imageUrls,
		public array $imageAlts,
	) {
	}
}
