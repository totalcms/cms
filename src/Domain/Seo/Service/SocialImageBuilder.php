<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\ImageWorks\Exception\InvalidImageParamException;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Rendering\Utilities\TemplatePlaceholder;
use TotalCMS\Domain\Security\Encryption\SiteKey;
use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Data\SocialCard;
use TotalCMS\Support\Config;

/**
 * Share images with an overlay: the page's title, a logo, the author's photo
 * drawn over the image that won the SEO chain, from layers the site stores.
 *
 * An overlay is `{"fit": "crop-top", "marks": [ … ]}` (or just the list of
 * marks), the layers ImageWorks' `marks` takes. It comes from the
 * collection's SEO card (`imageOptions`), else the Site SEO record
 * (`socialImageOptions`); the two are never merged. A record's SEO card can
 * turn it off (`imageAsIs`) for an image that was designed as a card.
 *
 * Text and `markimage` values can carry `${…}` placeholders: the record's
 * properties and dot paths, plus `${socialTitle}`, `${site}` and `${path}`.
 * A layer whose placeholders all come back empty is left out.
 *
 * The page head prints `/imageworks/social/{collection}/{id}.jpg?v=<hash>`,
 * never the layers. The route rebuilds the same card from the record and
 * draws it only when `v` matches: the hash is keyed with the site key, so
 * only a URL the site printed renders a card, and any other gets the plain
 * image. Editing the record or the overlay changes the hash, and the URL.
 *
 * Only a record can have a card: a head given a literal array (a Stacks
 * page) or nothing has no record to rebuild it from, and keeps the plain image.
 */
readonly class SocialImageBuilder
{
	public const ROUTE = '/imageworks/social';

	private const HASH_LENGTH = 16;
	/** Longest value a placeholder fills in; a body field could hold an article. */
	private const VALUE_MAX = 255;

	public function __construct(
		private SeoContextFactory $contexts,
		private MetaBuilder $metaBuilder,
		private ObjectFetcher $objects,
		private BuilderConfigService $builderConfig,
		private Config $config,
	) {
	}

	/** The meta with og:image swapped for the share card, when an overlay applies. */
	public function apply(SeoContext $ctx, MetaPayload $meta): MetaPayload
	{
		$card = $this->card($ctx, $meta);
		if ($card === null || !$card->hasOverlay()) {
			return $meta;
		}

		$path = parse_url($this->config->api . self::ROUTE . '/' . rawurlencode($card->collection) . '/' . rawurlencode($card->id) . '.jpg', PHP_URL_PATH);
		$url  = $ctx->settings->absolute((is_string($path) ? $path : '') . '?v=' . $card->hash);

		return $meta->withImage($url, $meta->ogImageAlt !== '' ? $meta->ogImageAlt : $card->firstText);
	}

	/**
	 * The card for a record, rebuilt for the share-image route: drawn with
	 * its overlay when `$hash` matches, plain otherwise. Null when the record
	 * cannot be read or has no share image at all.
	 */
	public function forRecord(string $collection, string $id, string $hash): ?SocialCard
	{
		try {
			$subject = $this->objects->fetchObject($collection, $id)->toArray();
		} catch (\Throwable) {
			return null;
		}

		$ctx  = $this->contexts->make($subject, ['collection' => $collection]);
		$card = $this->card($ctx, $this->metaBuilder->build($ctx));
		if ($card === null || $card->collection !== $collection || $card->id !== $id) {
			return null;
		}

		if ($card->hasOverlay() && hash_equals($card->hash, $hash)) {
			return $card;
		}

		return new SocialCard($card->collection, $card->id, $card->imageCollection, $card->imageId, $card->imageProperty, $card->gallery, SeoSettings::OG_IMAGE, '', '');
	}

	/** The card for a context, with or without an overlay; null with no record or no image. */
	public function card(SeoContext $ctx, MetaPayload $meta): ?SocialCard
	{
		$subject = $this->subject($ctx);
		$source  = $subject === null ? null : $this->source($ctx, $meta, $subject);
		if ($subject === null || $source === null) {
			return null;
		}

		$overlay = $ctx->fields->imageAsIs ? null : $this->overlay($ctx);
		$marks   = $overlay === null ? [] : $this->checked($this->filled($overlay['marks'], $ctx, $meta));
		$params  = SeoSettings::OG_IMAGE;

		if ($marks !== []) {
			$params['fit']   = $this->fit($overlay['fit'] ?? null) ?? $params['fit'];
			$params['marks'] = $marks;
		}

		$hash = $this->hash($subject, $meta, $params);
		// No site key (an install before setup): no hash to prove a URL, so no overlay.
		if ($hash === '') {
			$params = SeoSettings::OG_IMAGE;
			$marks  = [];
		}

		$firstText = '';
		foreach ($marks as $layer) {
			if (is_string($layer['marktext'] ?? null) && $layer['marktext'] !== '') {
				$firstText = str_replace("\n", ' ', $layer['marktext']);
				break;
			}
		}

		return new SocialCard(...[...$subject, ...$source, 'params' => $params, 'hash' => $hash, 'firstText' => $firstText]);
	}

	/**
	 * The record a head was given: a collection object, or a Site Builder
	 * page (a record in the pages collection).
	 *
	 * @return array{collection:string,id:string}|null
	 */
	private function subject(SeoContext $ctx): ?array
	{
		$id = $ctx->object['id'] ?? null;
		if (!is_scalar($id) || (string)$id === '') {
			return null;
		}

		if ($ctx->kind === 'object' && $ctx->collectionId !== '') {
			return ['collection' => $ctx->collectionId, 'id' => (string)$id];
		}

		// An ad-hoc array is a `page` too; only a real page record has a route and a template.
		if ($ctx->kind === 'page' && array_key_exists('route', $ctx->object) && array_key_exists('template', $ctx->object)) {
			return ['collection' => $this->builderConfig->getPagesCollectionId(), 'id' => (string)$id];
		}

		return null;
	}

	/**
	 * Which image won: the SEO card's own, the collection's mapped property,
	 * or the site default, in MetaBuilder's order, told apart by the URL each
	 * resolved to.
	 *
	 * @param array{collection:string,id:string} $subject
	 *
	 * @return array{imageCollection:string,imageId:string,imageProperty:string,gallery:bool}|null
	 */
	private function source(SeoContext $ctx, MetaPayload $meta, array $subject): ?array
	{
		$image = $meta->ogImage;
		if ($image === '') {
			return null;
		}

		if ($ctx->fields->hasImage() && ($ctx->imageUrls['seo.image'] ?? '') === $image) {
			return ['imageCollection' => $subject['collection'], 'imageId' => $subject['id'], 'imageProperty' => 'seo.image', 'gallery' => false];
		}

		$property = $ctx->seoBlock['image'];
		if ($property !== '' && ($ctx->imageUrls[$property] ?? '') === $image) {
			return [
				'imageCollection' => $subject['collection'],
				'imageId'         => $subject['id'],
				'imageProperty'   => $property,
				'gallery'         => SeoContextFactory::galleryFirstImage($ctx->object, $property) !== null,
			];
		}

		if ($image === $ctx->settings->defaultImage) {
			return ['imageCollection' => SeoSettingsLoader::RECORD, 'imageId' => SeoSettingsLoader::RECORD, 'imageProperty' => 'defaultImage', 'gallery' => false];
		}

		return null;
	}

	/**
	 * The collection's overlay, else the site's.
	 *
	 * @return array{fit:mixed,marks:list<array<array-key,mixed>>}|null
	 */
	private function overlay(SeoContext $ctx): ?array
	{
		$collection = self::normalized(SeoSettings::json($ctx->collectionMeta?->seo['imageOptions'] ?? null));

		return $collection ?? self::normalized($ctx->settings->socialImageOptions);
	}

	/**
	 * An overlay as stored: `{fit, marks}`, or the bare list of marks.
	 *
	 * @param array<array-key,mixed> $raw
	 *
	 * @return array{fit:mixed,marks:list<array<array-key,mixed>>}|null
	 */
	public static function normalized(array $raw): ?array
	{
		$list  = array_is_list($raw);
		$marks = $list ? $raw : ($raw['marks'] ?? []);
		$marks = is_array($marks) ? array_values(array_filter($marks, is_array(...))) : [];

		return $marks === [] ? null : ['fit' => $list ? null : ($raw['fit'] ?? null), 'marks' => $marks];
	}

	/**
	 * The layers with their placeholders filled; a layer whose placeholders
	 * all came back empty is left out.
	 *
	 * @param list<array<array-key,mixed>> $marks
	 *
	 * @return list<array<string,int|float|string>>
	 */
	private function filled(array $marks, SeoContext $ctx, MetaPayload $meta): array
	{
		$resolve = fn (string $key): string => $this->value($key, $ctx, $meta);
		$filled  = [];

		foreach ($marks as $layer) {
			$out   = [];
			$holes = 0;
			$empty = 0;

			foreach ($layer as $key => $value) {
				if (!is_scalar($value) || is_bool($value)) {
					continue;
				}

				if (is_string($value) && TemplatePlaceholder::extractKeys($value) !== []) {
					$holes++;
					$value = TemplatePlaceholder::renderOrEmpty($value, $resolve);
					$empty += $value === '' ? 1 : 0;
				}

				if ($value !== '') {
					$out[(string)$key] = $value;
				}
			}

			if ($out !== [] && ($holes === 0 || $empty < $holes)) {
				$filled[] = $out;
			}
		}

		return $filled;
	}

	/** What a placeholder fills in, as plain text. */
	private function value(string $key, SeoContext $ctx, MetaPayload $meta): string
	{
		$value = match ($key) {
			'socialTitle' => $meta->socialTitle,
			'site'        => $ctx->siteName,
			'path'        => (string)(parse_url($ctx->url, PHP_URL_PATH) ?? ''),
			default       => self::property($ctx->object, $key),
		};

		$text = trim((string)preg_replace('/[ \t\r\n]+/', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

		return mb_substr($text, 0, self::VALUE_MAX);
	}

	/**
	 * A record property by dot path. A list stands for its first item, so a
	 * field holding several authors fills in the first.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function property(array $data, string $key): string
	{
		$value = $data;
		foreach (explode('.', $key) as $segment) {
			$value = self::firstOf($value);
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return '';
			}
			$value = $value[$segment];
		}

		$value = self::firstOf($value);
		if (is_bool($value)) {
			return '';
		}

		return is_scalar($value) ? trim((string)$value) : '';
	}

	private static function firstOf(mixed $value): mixed
	{
		return is_array($value) && $value !== [] && array_is_list($value) ? $value[0] : $value;
	}

	/**
	 * The layers as ImageWorks would accept them from a URL, with their text
	 * as filled. Text skips the URL's checks: it comes from the record, not a
	 * visitor, and may break lines. An overlay ImageWorks would refuse draws
	 * nothing, and the plain image is shared instead.
	 *
	 * @param list<array<string,int|float|string>> $marks
	 *
	 * @return list<array<string,int|float|string>>
	 */
	private function checked(array $marks): array
	{
		if ($marks === []) {
			return [];
		}

		$masked = array_map(
			fn (array $layer): array => isset($layer['marktext']) ? ['marktext' => 'x'] + $layer : $layer,
			$marks,
		);

		try {
			$checked = ImageParamValidator::validate(['marks' => $masked])['marks'] ?? [];
		} catch (InvalidImageParamException) {
			return [];
		}

		if (!is_array($checked)) {
			return [];
		}

		$out = [];
		foreach ($checked as $i => $layer) {
			if (isset($marks[$i]['marktext'])) {
				$layer['marktext'] = (string)$marks[$i]['marktext'];
			}
			$out[] = $layer;
		}

		return $out;
	}

	/** The overlay's fit, when ImageWorks takes it. */
	private function fit(mixed $fit): ?string
	{
		if (!is_string($fit) || $fit === '') {
			return null;
		}

		try {
			$checked = ImageParamValidator::validate(['fit' => $fit])['fit'] ?? null;
		} catch (InvalidImageParamException) {
			return null;
		}

		return is_string($checked) ? $checked : null;
	}

	/**
	 * Keyed with the site key: the record, the image (by path, so a request
	 * over another scheme still matches) and every value drawn.
	 *
	 * @param array{collection:string,id:string} $subject
	 * @param array<string,mixed> $params
	 */
	private function hash(array $subject, MetaPayload $meta, array $params): string
	{
		$key = SiteKey::get();
		if ($key === null || !isset($params['marks'])) {
			return '';
		}

		$image = parse_url($meta->ogImage);
		$image = is_array($image) ? ($image['path'] ?? '') . '?' . ($image['query'] ?? '') : $meta->ogImage;

		return substr(hash_hmac('sha256', (string)json_encode([$subject, $image, $params]), $key), 0, self::HASH_LENGTH);
	}
}
