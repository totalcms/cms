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
			'headline'         => mb_substr($meta->rawTitle, 0, self::HEADLINE_LENGTH),
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
		if ($this->str($ctx, 'author') !== '') {
			$node['author'] = ['@type' => 'Person', 'name' => $this->str($ctx, 'author')];
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

	private function str(SeoContext $ctx, string $key): string
	{
		$value = $ctx->object[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
