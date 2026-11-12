<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\ImageWorks\Exception\InvalidImageParamException;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Rendering\Utilities\TemplatePlaceholder;
use TotalCMS\Domain\Security\Encryption\SiteKey;
use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Data\SocialCard;
use TotalCMS\Domain\Seo\Data\SocialCardOutcome;
use TotalCMS\Domain\Seo\Data\SocialCardReason;
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
 * Overlays are drawn with layered marks, a Pro feature: below Pro every page
 * shares the plain image.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") It decides a card from the
 * record, the overlay, the site key and the edition; its data objects make up
 * most of the count.
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
		private SocialCardRecorder $recorder,
		private EditionFeatureService $editions,
	) {
	}

	/** The meta with og:image swapped for the share card, when an overlay applies. */
	public function apply(SeoContext $ctx, MetaPayload $meta): MetaPayload
	{
		$outcome = $this->explain($ctx, $meta);
		$card    = $outcome->card;
		if (!$card instanceof SocialCard || !$card->hasOverlay()) {
			$this->recorder->record($outcome);

			return $meta;
		}

		$path = parse_url($this->config->api . self::ROUTE . '/' . rawurlencode($card->collection) . '/' . rawurlencode($card->id) . '.jpg', PHP_URL_PATH);
		$url  = $ctx->settings->absolute((is_string($path) ? $path : '') . '?v=' . $card->hash);
		$this->recorder->record($outcome->withOgImage($url));

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
		if (!$card instanceof SocialCard || $card->collection !== $collection || $card->id !== $id) {
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
		return $this->explain($ctx, $meta)->card;
	}

	/**
	 * The card for a context and why it is what it is. The card is null with
	 * no record or no image; otherwise it is plain unless the reason is Drawn.
	 */
	public function explain(SeoContext $ctx, MetaPayload $meta): SocialCardOutcome
	{
		if ($meta->ogImage === '') {
			return new SocialCardOutcome(SocialCardReason::NoImage, null, '');
		}

		$subject = $this->subject($ctx);
		$source  = $subject === null ? null : $this->source($ctx, $meta, $subject);
		if ($subject === null || $source === null) {
			return new SocialCardOutcome($subject === null ? SocialCardReason::NoRecord : SocialCardReason::Untraced, null, $meta->ogImage);
		}

		[$reason, $marks, $fit, $detail, $layer] = $this->layers($ctx, $meta);
		$params                                  = SeoSettings::OG_IMAGE;

		if ($marks !== []) {
			$params['fit']   = $fit ?? $params['fit'];
			$params['marks'] = $marks;
		}

		$hash = $this->hash($subject, $meta, $params);
		// No site key (an install before setup): no hash to prove a URL, so no overlay.
		if ($marks !== [] && $hash === '') {
			$params = SeoSettings::OG_IMAGE;
			$marks  = [];
			$reason = SocialCardReason::NoSiteKey;
		}

		$card = new SocialCard(...[...$subject, ...$source, 'params' => $params, 'hash' => $hash, 'firstText' => $this->firstText($marks)]);

		return new SocialCardOutcome($reason, $card, $meta->ogImage, $detail, $layer);
	}

	/**
	 * The layers to draw, and the reason when there are none: as is, no
	 * overlay, every layer empty, or a layer ImageWorks refused.
	 *
	 * @return array{0:SocialCardReason,1:list<array<string,int|float|string>>,2:?string,3:string,4:?int}
	 */
	private function layers(SeoContext $ctx, MetaPayload $meta): array
	{
		// Overlays are drawn with layered marks, a Pro feature.
		if (!$this->editions->can(EditionFeature::IMAGE_LAYERS)) {
			return [SocialCardReason::NeedsPro, [], null, '', null];
		}

		if ($ctx->fields->imageAsIs) {
			return [SocialCardReason::AsIs, [], null, '', null];
		}

		$overlay = $this->overlay($ctx);
		if ($overlay === null) {
			return [SocialCardReason::NoOverlay, [], null, '', null];
		}

		$filled = $this->filled($overlay['marks'], $ctx, $meta);
		if ($filled === []) {
			return [SocialCardReason::LayersEmpty, [], null, '', null];
		}

		[$marks, $layer, $detail] = $this->checked($filled);
		if ($marks === []) {
			return [SocialCardReason::LayerRefused, [], null, $detail, $layer];
		}

		return [SocialCardReason::Drawn, $marks, $this->fit($overlay['fit'] ?? null), '', null];
	}

	/**
	 * The first text a layer draws, for og:image:alt when the image has none.
	 *
	 * @param list<array<string,int|float|string>> $marks
	 */
	private function firstText(array $marks): string
	{
		foreach ($marks as $layer) {
			if (is_string($layer['marktext'] ?? null) && $layer['marktext'] !== '') {
				return str_replace("\n", ' ', $layer['marktext']);
			}
		}

		return '';
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
			default       => $this->property($ctx->object, $key),
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
	private function property(array $data, string $key): string
	{
		$value = $data;
		foreach (explode('.', $key) as $segment) {
			$value = $this->firstOf($value);
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return '';
			}
			$value = $value[$segment];
		}

		$value = $this->firstOf($value);
		if (is_bool($value)) {
			return '';
		}

		return is_scalar($value) ? trim((string)$value) : '';
	}

	private function firstOf(mixed $value): mixed
	{
		return is_array($value) && $value !== [] && array_is_list($value) ? $value[0] : $value;
	}

	/**
	 * The layers as ImageWorks would accept them from a URL, with their text
	 * as filled. Text skips the URL's checks: it comes from the record, not a
	 * visitor, and may break lines. An overlay ImageWorks would refuse draws
	 * nothing, and the plain image is shared instead.
	 *
	 * Each layer is checked on its own first, so a refusal can name the layer
	 * and the parameter; then the whole list, for the limits on the list itself.
	 *
	 * @param list<array<string,int|float|string>> $marks
	 *
	 * @return array{0:list<array<string,int|float|string>>,1:?int,2:string} The layers, or none with the refused layer and the reason
	 */
	private function checked(array $marks): array
	{
		$masked = array_map(
			fn (array $layer): array => isset($layer['marktext']) ? ['marktext' => 'x'] + $layer : $layer,
			$marks,
		);

		foreach ($masked as $i => $layer) {
			try {
				ImageParamValidator::validate(['marks' => [$layer]]);
			} catch (InvalidImageParamException $e) {
				return [[], $i, $e->getMessage()];
			}
		}

		try {
			$checked = ImageParamValidator::validate(['marks' => $masked])['marks'] ?? [];
		} catch (InvalidImageParamException $e) {
			return [[], null, $e->getMessage()];
		}

		if (!is_array($checked)) {
			return [[], null, ''];
		}

		$out = [];
		foreach ($checked as $i => $layer) {
			if (isset($marks[$i]['marktext'])) {
				$layer['marktext'] = (string)$marks[$i]['marktext'];
			}
			$out[] = $layer;
		}

		return [$out, null, ''];
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
