<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * An `Article` (or `BlogPosting`) node for a page or object whose resolved
 * content type says it is one.
 *
 * MetaBuilder resolves the type — the record's card, then the collection
 * (which carries the schema default: blog posts are BlogPostings), then a
 * plain web page — so this provider only reads `MetaPayload::$contentType`.
 * A Site Builder page qualifies the same way an object does: its card
 * alone decides.
 */
final class ArticleProvider implements JsonLdProvider
{
	/** Schema.org caps a useful headline well before this; 110 is the common ceiling. */
	private const HEADLINE_LENGTH = 110;

	/** @return list<array<string,mixed>> */
	public function nodes(SeoContext $ctx, MetaPayload $meta): array
	{
		if (!$this->applies($ctx, $meta)) {
			return [];
		}

		$node = [
			'@type'            => $meta->contentType === 'blogposting' ? 'BlogPosting' : 'Article',
			'@id'              => $ctx->url . '#article',
			'headline'         => mb_substr($meta->name, 0, self::HEADLINE_LENGTH),
			'isPartOf'         => ['@id' => WebPageProvider::id($ctx)],
			'mainEntityOfPage' => ['@id' => WebPageProvider::id($ctx)],
		];

		if ($meta->description !== '') {
			$node['description'] = $meta->description;
		}
		if ($meta->ogImage !== '') {
			$node['image'] = $meta->ogImage;
		}

		$published = $this->str($ctx, 'date') !== '' ? $this->str($ctx, 'date') : $this->str($ctx, 'created');
		if ($published !== '') {
			$node['datePublished'] = $published;
		}
		if ($this->str($ctx, 'updated') !== '') {
			$node['dateModified'] = $this->str($ctx, 'updated');
		}
		$author = $this->author($ctx);
		if ($author !== []) {
			$node['author'] = $author;
		}
		if (OrganizationProvider::applies($ctx)) {
			$node['publisher'] = ['@id' => OrganizationProvider::id($ctx)];
		}

		return [$node];
	}

	/**
	 * An article needs a WebPage to be part of (a page or object with a URL)
	 * and an article content type.
	 */
	private function applies(SeoContext $ctx, MetaPayload $meta): bool
	{
		return WebPageProvider::applies($ctx) && $meta->contentType !== 'website';
	}

	/**
	 * The author node. A string names a Person; an array is the node itself,
	 * so a template can point at a Person it declares elsewhere in the graph
	 * (`{'@id': base ~ '/about#founder'}`) — search engines resolve the
	 * reference and read that node's `url` and `sameAs` as the author's,
	 * which a bare name never gives them. The Person type is filled in only
	 * when the array is more than a reference; a reference carries `@id` alone.
	 *
	 * @return array<string,mixed>
	 */
	private function author(SeoContext $ctx): array
	{
		$value = $ctx->object['author'] ?? '';
		if (is_array($value)) {
			if ($value === [] || array_is_list($value)) {
				return [];
			}

			return isset($value['@type']) || (isset($value['@id']) && count($value) === 1) ? $value : ['@type' => 'Person'] + $value;
		}

		$name = is_string($value) ? trim($value) : '';

		return $name === '' ? [] : ['@type' => 'Person', 'name' => $name];
	}

	private function str(SeoContext $ctx, string $key): string
	{
		$value = $ctx->object[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
