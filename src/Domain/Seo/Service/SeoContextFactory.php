<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

/**
 * Resolves the subject of a render — a Site Builder page, a collection object,
 * or nothing at all — into a SeoContext.
 *
 * It is the one place that reads collection meta and resolves object URLs and
 * image paths into a context; MetaBuilder and the JSON-LD builders consume the
 * result and stay pure.
 */
readonly class SeoContextFactory
{
	/** No collection mapping: every value falls through to the site defaults. */
	private const EMPTY_BLOCK = ['type' => '', 'title' => '', 'description' => '', 'image' => ''];

	/** The default mapping for `blog`-schema collections. */
	private const ARTICLE_BLOCK = ['type' => 'article', 'title' => 'title', 'description' => 'summary', 'image' => 'image'];

	/**
	 * Site Builder pages carry a `title`; their description and social image
	 * live on the `seo` card only, so there is nothing else to map.
	 */
	private const PAGE_BLOCK = ['type' => '', 'title' => 'title', 'description' => '', 'image' => ''];

	/**
	 * Per-schema default mappings, keyed by schema id. Each reserved schema that
	 * carries an obvious title / description / image trio gets one so a site that
	 * never opens the collection's `seo` card still emits useful tags. Every
	 * property named here exists in the matching `resources/schemas/*.json`.
	 * A schema absent from the map falls back to EMPTY_BLOCK.
	 *
	 * @var array<string,array{type:string,title:string,description:string,image:string}>
	 */
	private const SCHEMA_BLOCKS = [
		'blog'            => self::ARTICLE_BLOCK,
		'blog-legacy'     => self::ARTICLE_BLOCK,
		'feed'            => ['type' => 'article', 'title' => 'title', 'description' => 'content', 'image' => 'image'],
		// An episode is a media item, not an article — `website` is the right
		// Open Graph type, and it keeps ArticleProvider out of the JSON-LD.
		'podcast-episode' => ['type' => 'website', 'title' => 'title', 'description' => 'summary', 'image' => 'art'],
	];

	public function __construct(
		private SeoSettingsLoader $seoSettings,
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private MediaTwigAdapter $media,
		private BuilderConfigService $builderConfig,
	) {
	}

	/**
	 * @param mixed $subject A page record, a collection object, or null
	 * @param array<string,mixed> $options `collection` names the object's collection when the object doesn't
	 */
	public function make(mixed $subject, array $options = []): SeoContext
	{
		$settings = $this->seoSettings->load();
		$siteName = $settings->siteName;

		if (!is_array($subject)) {
			return new SeoContext('none', [], '', null, self::EMPTY_BLOCK, SeoFields::fromArray([]), $settings, $siteName, '', [], []);
		}

		/** @var array<string,mixed> $subject */
		$fields = SeoFields::fromArray(is_array($subject['seo'] ?? null) ? $subject['seo'] : []);

		if ($this->isPage($subject)) {
			return $this->pageContext($subject, $fields, $settings, $siteName);
		}

		return $this->objectContext($subject, $fields, $settings, $siteName, $options);
	}

	/**
	 * A Site Builder page record — `RouteMatch::$pageData` — is the only array
	 * that carries both a route and a template.
	 *
	 * @param array<string,mixed> $subject
	 */
	private function isPage(array $subject): bool
	{
		return array_key_exists('route', $subject) && array_key_exists('template', $subject);
	}

	/** @param array<string,mixed> $page */
	private function pageContext(array $page, SeoFields $fields, SeoSettings $settings, string $siteName): SeoContext
	{
		$route = (string)($page['route'] ?? '');

		// A templated route (`/blog/{id}`) is a pattern, not an address — the
		// page itself has no single canonical URL.
		$url = ($route !== '' && !str_contains($route, '{')) ? $settings->absolute($route) : '';

		return new SeoContext(
			'page',
			$page,
			'',
			null,
			self::PAGE_BLOCK,
			$fields,
			$settings,
			$siteName,
			$url,
			$this->imageUrls($page, $this->builderConfig->getPagesCollectionId(), self::PAGE_BLOCK, $fields, $settings),
			$this->imageAlts($page, self::PAGE_BLOCK, $fields),
		);
	}

	/**
	 * @param array<string,mixed> $object
	 * @param array<string,mixed> $options
	 */
	private function objectContext(array $object, SeoFields $fields, SeoSettings $settings, string $siteName, array $options): SeoContext
	{
		$collectionId = trim((string)($options['collection'] ?? $object['_collection'] ?? ''));
		$meta         = $collectionId === '' ? null : $this->collectionFetcher->fetchCollection($collectionId);
		$block        = $this->resolveBlock($meta);

		return new SeoContext(
			'object',
			$object,
			$collectionId,
			$meta,
			$block,
			$fields,
			$settings,
			$siteName,
			$this->objectUrl($meta, $object, $settings),
			$this->imageUrls($object, $collectionId, $block, $fields, $settings),
			$this->imageAlts($object, $block, $fields),
		);
	}

	/**
	 * The collection's `seo` card merged OVER the schema default, key by key:
	 * a mapped value wins, an unmapped one falls through to the default. A blog
	 * collection that maps only `image` therefore keeps `article` / `title` /
	 * `summary` for the keys it left alone.
	 *
	 * @return array{type:string,title:string,description:string,image:string}
	 */
	private function resolveBlock(?CollectionData $meta): array
	{
		if ($meta === null) {
			return self::EMPTY_BLOCK;
		}

		$default = self::SCHEMA_BLOCKS[$meta->schema] ?? self::EMPTY_BLOCK;

		return [
			'type'        => $this->blockValue($meta, 'type', $default['type']),
			'title'       => $this->blockValue($meta, 'title', $default['title']),
			'description' => $this->blockValue($meta, 'description', $default['description']),
			'image'       => $this->blockValue($meta, 'image', $default['image']),
		];
	}

	/** One `seo` card value off the collection, falling back to the schema default. */
	private function blockValue(CollectionData $meta, string $key, string $default): string
	{
		$value = trim((string)($meta->seo[$key] ?? ''));

		return $value !== '' ? $value : $default;
	}

	/** @param array<string,mixed> $object */
	private function objectUrl(?CollectionData $meta, array $object, SeoSettings $settings): string
	{
		if ($meta === null) {
			return '';
		}

		$url = $this->urlBuilder->buildUrl($meta, $object);

		// An unresolved placeholder leaves an empty path segment behind; a
		// half-built canonical is worse than none at all.
		if ($url === '' || $this->urlBuilder->hasEmptySegments($url)) {
			return '';
		}

		return $settings->absolute($url);
	}

	/**
	 * Pre-resolve the absolute ImageWorks URLs the meta/JSON-LD builders may
	 * need: the collection's mapped image property, and the `seo` card's own
	 * image. Doing it here keeps the Twig media adapter out of the builders.
	 *
	 * @param array<string,mixed> $object
	 * @param array{type:string,title:string,description:string,image:string} $block
	 *
	 * @return array<string,string>
	 */
	private function imageUrls(array $object, string $collectionId, array $block, SeoFields $fields, SeoSettings $settings): array
	{
		$urls = [];

		if ($block['image'] !== '') {
			$urls[$block['image']] = $this->imageUrl($object, $collectionId, $block['image'], $settings);
		}

		if ($fields->hasImage()) {
			$urls['seo.image'] = $this->imageUrl($object, $collectionId, 'seo.image', $settings);
		}

		return $urls;
	}

	/**
	 * The alt text of those same two images, under the same keys, so a builder
	 * can describe whichever image it ends up choosing. Only an image that
	 * actually exists contributes a key — an alt left behind on a cleared image
	 * property must not describe the image that wins instead.
	 *
	 * @param array<string,mixed> $object
	 * @param array{type:string,title:string,description:string,image:string} $block
	 *
	 * @return array<string,string>
	 */
	private function imageAlts(array $object, array $block, SeoFields $fields): array
	{
		$alts = [];

		if ($block['image'] !== '') {
			$image = $object[$block['image']] ?? null;
			if (is_array($image) && trim((string)($image['name'] ?? '')) !== '') {
				$alts[$block['image']] = trim((string)($image['alt'] ?? ''));
			}
		}

		if ($fields->hasImage()) {
			$alts['seo.image'] = trim((string)($fields->image['alt'] ?? ''));
		}

		return $alts;
	}

	/** @param array<string,mixed> $object */
	private function imageUrl(array $object, string $collectionId, string $property, SeoSettings $settings): string
	{
		if ($collectionId === '') {
			return '';
		}

		// imagePath() returns a root-relative path (the host is stripped when
		// building the ImageWorks API URL) — social crawlers need it absolute.
		return $settings->absolute($this->media->imagePath($object, SeoSettings::OG_IMAGE, ['collection' => $collectionId, 'property' => $property]));
	}
}
