<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Service\JsonLd\JsonLdProvider;

/**
 * Assembles the page's JSON-LD `@graph` from its providers and renders the
 * `<script>` tag.
 *
 * The builder itself holds no schema.org knowledge — it concatenates what the
 * providers return, keeps the first node for any repeated `@id` (so a provider
 * registered earlier wins over a later one describing the same entity) and
 * encodes the result.
 */
class JsonLdBuilder
{
	/** @var list<JsonLdProvider> */
	private array $providers;

	public function __construct(JsonLdProvider ...$providers)
	{
		$this->providers = array_values($providers);
	}

	/**
	 * The merged, de-duplicated `@graph` list.
	 *
	 * `$extra` is whatever the template passed as `options.jsonld`. It lands
	 * after every provider node, so a template node carrying an `@id` a
	 * provider already emitted loses the dedupe — the core description of an
	 * entity wins, and a template referencing `{base}/#organization` links to
	 * the real node instead of replacing it.
	 *
	 * @param list<array<string,mixed>> $extra
	 *
	 * @return list<array<string,mixed>>
	 */
	public function graph(SeoContext $ctx, MetaPayload $meta, array $extra = []): array
	{
		$nodes = [];

		foreach ($this->providers as $provider) {
			foreach ($provider->nodes($ctx, $meta) as $node) {
				$nodes[] = $node;
			}
		}

		foreach ($extra as $node) {
			$nodes[] = $node;
		}

		$graph = [];
		$seen  = [];

		foreach ($nodes as $node) {
			$id = $node['@id'] ?? null;

			// Nodes without an @id can't be compared, so they pass through.
			if (is_string($id) && $id !== '') {
				if (isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
			}

			$graph[] = $node;
		}

		return $graph;
	}

	/**
	 * The `<script type="application/ld+json">` block, or `''` when JSON-LD is
	 * switched off or there is nothing to say.
	 *
	 * JSON_HEX_TAG is what keeps a `</script>` inside any value from closing
	 * the tag early, so the output is safe to print unescaped. Template-supplied
	 * nodes go through this same encode — they are never interpolated.
	 *
	 * @param list<array<string,mixed>> $extra
	 */
	public function script(SeoContext $ctx, MetaPayload $meta, array $extra = []): string
	{
		if (!$ctx->settings->emitJsonLd) {
			return '';
		}

		$graph = $this->graph($ctx, $meta, $extra);
		if ($graph === []) {
			return '';
		}

		$json = json_encode(
			['@context' => 'https://schema.org', '@graph' => $graph],
			JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);

		return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
	}
}
