<?php

declare(strict_types=1);

use TotalCMS\Support\PathResolver;

/**
 * The four bundled starters are the reference implementation of `cms.seo` —
 * every new Site Builder site inherits its `<head>` from them. A starter that
 * still hand-rolls `<title>` would emit two of them alongside `head()`.
 */
$startersDir = PathResolver::packageRoot() . '/resources/builder/starters';
$starters    = ['blog', 'business', 'minimal', 'portfolio'];

it('renders the head through cms.seo in every starter layout', function (string $starter) use ($startersDir): void {
	$layout = file_get_contents("{$startersDir}/{$starter}/layouts/default.twig");

	expect($layout)->toBeString()
		->toContain('cms.seo.head(')
		->toContain('{% block seo %}')
		->not->toContain('<title>')
		->not->toContain('<meta name="description"');
})->with($starters);

it('leaves no page overriding the retired title and description blocks', function (string $starter) use ($startersDir): void {
	$pages = glob("{$startersDir}/{$starter}/pages/{,*/}*.twig", GLOB_BRACE) ?: [];
	expect($pages)->not->toBeEmpty();

	foreach ($pages as $page) {
		expect(file_get_contents($page))
			->not->toContain('{% block title %}')
			->not->toContain('{% block description %}');
	}
})->with($starters);

it('describes the post, not the page, on the blog starter post route', function () use ($startersDir): void {
	expect(file_get_contents("{$startersDir}/blog/pages/blog/post.twig"))
		->toContain("cms.seo.head(post, {collection: 'blog'})");
});
