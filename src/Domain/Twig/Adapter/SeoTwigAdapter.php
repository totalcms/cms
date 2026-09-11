<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Service\JsonLdBuilder;
use TotalCMS\Domain\Seo\Service\MetaBuilder;
use TotalCMS\Domain\Seo\Service\SeoContextFactory;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use Twig\Markup;

/**
 * Twig sub-adapter for the `<head>` SEO block.
 *
 * Accessed in Twig as `cms.seo.head()` and its granular siblings:
 *
 *     {{ cms.seo.head(page) }}                        {# a Site Builder page #}
 *     {{ cms.seo.head(post, {collection: 'blog'}) }}  {# a collection object  #}
 *     {{ cms.seo.head() }}                            {# site defaults only   #}
 *
 * The subject is whatever the template is rendering — a page record, a
 * collection object, or nothing. `options.collection` names the collection for
 * an object that doesn't carry `_collection` itself.
 *
 * Every method returns a `Twig\Markup`, so the output prints correctly whether
 * or not the calling template has autoescaping on — no `|raw` needed, the same
 * contract as `cms.assetsHead()`.
 */
final readonly class SeoTwigAdapter
{
	/** Everything `head()` prints, in document order. */
	private const ALL_PARTS = ['title', 'description', 'robots', 'canonical', 'og', 'twitter', 'verification'];

	/**
	 * TwigEngine arrives as a factory, not an instance: this adapter hangs off
	 * `cms`, and `cms` is a global on the very engine it renders through
	 * (TwigEngine → TotalCMSTwigExtension → TotalCMSTwigAdapter → here). Taking
	 * the engine directly is a container-level circular dependency; deferring
	 * the lookup to first render breaks it. Same treatment LoadMoreRenderer
	 * gets, for the same reason.
	 *
	 * @param \Closure(): TwigEngine $twigEngineFactory
	 */
	public function __construct(
		private SeoContextFactory $contexts,
		private MetaBuilder $metaBuilder,
		private JsonLdBuilder $jsonLdBuilder,
		private \Closure $twigEngineFactory,
	) {
	}

	/**
	 * The complete block: title, description, robots, canonical, Open Graph,
	 * Twitter, verification and JSON-LD.
	 *
	 * @param array<string,mixed> $options
	 */
	public function head(mixed $subject = null, array $options = []): Markup
	{
		[$ctx, $meta] = $this->resolve($subject, $options);

		$jsonld = $this->jsonLdBuilder->script($ctx, $meta, self::extraNodes($options));
		$parts  = self::ALL_PARTS;

		// Only ask for the JSON-LD slice when there is a script to print —
		// otherwise the template emits an empty line at the end of the block.
		if ($ctx->settings->emitJsonLd && $jsonld !== '') {
			$parts[] = 'jsonld';
		}

		return $this->markup($ctx, $meta, $parts, $jsonld);
	}

	/**
	 * Just `<title>`.
	 *
	 * @param array<string,mixed> $options
	 */
	public function title(mixed $subject = null, array $options = []): Markup
	{
		return $this->slice($subject, $options, ['title']);
	}

	/**
	 * The non-social meta tags: description, robots and site verification.
	 *
	 * @param array<string,mixed> $options
	 */
	public function meta(mixed $subject = null, array $options = []): Markup
	{
		return $this->slice($subject, $options, ['description', 'robots', 'verification']);
	}

	/**
	 * The Open Graph and Twitter card tags.
	 *
	 * @param array<string,mixed> $options
	 */
	public function og(mixed $subject = null, array $options = []): Markup
	{
		return $this->slice($subject, $options, ['og', 'twitter']);
	}

	/**
	 * Just the canonical link tag.
	 *
	 * @param array<string,mixed> $options
	 */
	public function canonical(mixed $subject = null, array $options = []): Markup
	{
		return $this->slice($subject, $options, ['canonical']);
	}

	/**
	 * Just the JSON-LD `<script>`. Already breakout-safe, so it skips the
	 * template entirely.
	 *
	 * @param array<string,mixed> $options
	 */
	public function jsonld(mixed $subject = null, array $options = []): Markup
	{
		[$ctx, $meta] = $this->resolve($subject, $options);

		return new Markup($this->jsonLdBuilder->script($ctx, $meta, self::extraNodes($options)), 'UTF-8');
	}

	/**
	 * The same values `head()` prints, as a plain array — for a template that
	 * needs one of them on its own (a share button's title, a preview card) or
	 * wants to build its own tags:
	 *
	 *     {% set seo = cms.seo.data(page) %}
	 *     <img src="{{ seo.ogImage }}" alt="{{ seo.ogImageAlt }}">
	 *
	 * Every `MetaPayload` field appears under its own name, plus `site` for the
	 * Site SEO record's own values. Not markup — nothing here is escaped, so a
	 * template printing one of these escapes it as usual.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	public function data(mixed $subject = null, array $options = []): array
	{
		[$ctx, $meta] = $this->resolve($subject, $options);
		$settings     = $ctx->settings;

		return array_merge(get_object_vars($meta), [
			'site' => [
				'name'             => $settings->siteName,
				'baseUrl'          => $settings->baseUrl,
				'defaultImage'     => $settings->defaultImage,
				'defaultImageAlt'  => $settings->defaultImageAlt,
				'organizationName' => $settings->organizationName,
				'organizationLogo' => $settings->organizationLogo,
				'sameAs'           => $settings->sameAs,
				'contactEmail'     => $settings->contactEmail,
				'contactUrl'       => $settings->contactUrl,
			],
		]);
	}

	/**
	 * `options.jsonld` as the builder wants it: a list of node arrays. The
	 * option arrives from a template, so anything that cannot be a node is
	 * dropped rather than allowed to reach `json_encode`: a scalar would land
	 * in the `@graph` as a bare string, and a list-shaped entry — the easy
	 * mistake of wrapping one node in an extra `[...]` — would encode as a
	 * nested JSON array, which is not a node either. An empty array stays: it
	 * is ambiguous in PHP, and the builder ignores a node with no `@type`.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function extraNodes(array $options): array
	{
		$extra = $options['jsonld'] ?? null;
		if (!is_array($extra)) {
			return [];
		}

		$nodes = [];
		foreach ($extra as $node) {
			if (!is_array($node) || (array_is_list($node) && $node !== [])) {
				continue;
			}

			/** @var array<string,mixed> $node */
			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * @param array<string,mixed> $options
	 * @param list<string> $parts
	 */
	private function slice(mixed $subject, array $options, array $parts): Markup
	{
		[$ctx, $meta] = $this->resolve($subject, $options);

		return $this->markup($ctx, $meta, $parts, '');
	}

	/**
	 * Context and meta for one call — built once, so a granular method costs no
	 * more than the slice it prints.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array{SeoContext,MetaPayload}
	 */
	private function resolve(mixed $subject, array $options): array
	{
		$ctx = $this->contexts->make($subject, $options);

		return [$ctx, $this->metaBuilder->build($ctx)];
	}

	/**
	 * The template owns the escaping (it turns autoescape on for its body); the
	 * trim keeps the granular methods to exactly their own tag.
	 *
	 * @param list<string> $parts
	 */
	private function markup(SeoContext $ctx, MetaPayload $meta, array $parts, string $jsonld): Markup
	{
		$html = ($this->twigEngineFactory)()->render('seo/head.twig', [
			'meta'     => $meta,
			'settings' => $ctx->settings,
			'parts'    => $parts,
			'jsonld'   => $jsonld,
		]);

		return new Markup(trim($html), 'UTF-8');
	}
}
