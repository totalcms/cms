<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * An `Article` node for collection objects the site publishes as articles.
 *
 * The collection's SEO card decides this by default (`seo.type === 'article'`);
 * the object's own `jsonldType` overrides it in both directions — `article`
 * opts a one-off object in, `none` opts it out.
 */
final class ArticleProvider implements JsonLdProvider
{
	/** Schema.org caps a useful headline well before this; 110 is the common ceiling. */
	private const HEADLINE_LENGTH = 110;

	/** @return list<array<string,mixed>> */
	public function nodes(SeoContext $ctx, MetaPayload $meta): array
	{
		if (!$this->applies($ctx)) {
			return [];
		}

		$node = [
			'@type'            => 'Article',
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
	 * An article needs an object, a URL to anchor the `@id` to, and either the
	 * collection's `article` type or an explicit per-object opt-in.
	 */
	private function applies(SeoContext $ctx): bool
	{
		if ($ctx->kind !== 'object' || $ctx->url === '' || !WebPageProvider::applies($ctx)) {
			return false;
		}

		$type = $ctx->fields->jsonldType;
		if ($type === 'article') {
			return true;
		}

		return $type !== 'none' && $ctx->seoBlock['type'] === 'article';
	}

	private function str(SeoContext $ctx, string $key): string
	{
		$value = $ctx->object[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
