<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Data\SeoContext;
use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\JsonLd\ArticleProvider;
use TotalCMS\Domain\Seo\Service\JsonLd\BreadcrumbProvider;
use TotalCMS\Domain\Seo\Service\JsonLd\OrganizationProvider;
use TotalCMS\Domain\Seo\Service\JsonLd\WebPageProvider;
use TotalCMS\Domain\Seo\Service\JsonLd\WebSiteProvider;
use TotalCMS\Domain\Seo\Service\JsonLdBuilder;
use TotalCMS\Domain\Seo\Service\MetaBuilder;

describe('JsonLdBuilder', function (): void {
	$build = fn (SeoContext $ctx): array => (new JsonLdBuilder(new OrganizationProvider(), new WebSiteProvider(), new WebPageProvider(), new BreadcrumbProvider(), new ArticleProvider()))->graph($ctx, (new MetaBuilder())->build($ctx));

	test('an article page yields the five nodes, cross-referenced by @id, once each', function () use ($build): void {
		$graph = $build(seoCtx(['settings' => SeoSettings::fromArray(['siteName' => 'Bistro', 'organizationName' => 'Bistro Ltd', 'organizationLogo' => 'https://cdn/logo.png', 'sameAs' => 'https://x.com/b'], 'example.com')]));
		$types = array_column($graph, '@type');
		expect($types)->toBe(['Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'Article']);
		$byId = array_column($graph, null, '@id');
		expect($byId['https://example.com/#website']['publisher'])->toBe(['@id' => 'https://example.com/#organization']);
		expect($byId['https://example.com/blog/hello#webpage']['isPartOf'])->toBe(['@id' => 'https://example.com/#website']);
		expect($byId['https://example.com/blog/hello#article']['mainEntityOfPage'])->toBe(['@id' => 'https://example.com/blog/hello#webpage']);
		expect($byId['https://example.com/#organization']['sameAs'])->toBe(['https://x.com/b']);
		expect(count(array_unique(array_column($graph, '@id'))))->toBe(count($graph));
	});

	test('a website page has no Article and no breadcrumb beyond Home', function () use ($build): void {
		$graph = $build(seoCtx(['seoBlock' => ['type' => '', 'title' => '', 'description' => '', 'image' => '']]));
		expect(array_column($graph, '@type'))->not->toContain('Article');
		$byType = array_column($graph, null, '@type');
		expect($byType['BreadcrumbList']['itemListElement'])->toHaveCount(2);
	});

	test('no context yields Organization and WebSite only', function () use ($build): void {
		expect(array_column($build(seoCtx(['kind' => 'none', 'object' => [], 'url' => ''])), '@type'))->toBe(['Organization', 'WebSite']);
	});

	test('jsonldType none suppresses the Article, and the script cannot break out', function () use ($build): void {
		$ctx = seoCtx(['fields' => SeoFields::fromArray(['jsonldType' => 'none', 'title' => '</script><script>alert(1)</script>'])]);
		expect(array_column($build($ctx), '@type'))->not->toContain('Article');
		$script = (new JsonLdBuilder(new WebPageProvider()))->script($ctx, (new MetaBuilder())->build($ctx));
		expect($script)->toStartWith('<script type="application/ld+json">')->not->toContain('</script><script>');
	});

	test('emitJsonLd off yields an empty script', function (): void {
		$ctx = seoCtx(['settings' => SeoSettings::fromArray(['emitJsonLd' => false], 'x')]);
		expect((new JsonLdBuilder(new WebSiteProvider()))->script($ctx, (new MetaBuilder())->build($ctx)))->toBe('');
	});
});
