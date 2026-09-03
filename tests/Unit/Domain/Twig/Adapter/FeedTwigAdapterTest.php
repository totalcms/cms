<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Twig\Adapter\FeedTwigAdapter;
use TotalCMS\Support\Config;
use Twig\Markup;

/**
 * The adapter is thin — its job is to hand FeedWriter's output back in a form
 * a template can print. That form is the part worth pinning: a plain string
 * would be HTML-escaped by any template that has not turned autoescape off,
 * turning the whole document into &lt;rss&gt;.
 */
function feedAdapter(): FeedTwigAdapter
{
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = 'example.com';

	return new FeedTwigAdapter(new FeedWriter($config));
}

/** @return array<string,mixed> */
function adapterMeta(array $overrides = []): array
{
	return array_merge([
		'title'       => 'Total CMS Releases',
		'link'        => 'https://example.com/changelog',
		'description' => 'One entry per release.',
	], $overrides);
}

describe('FeedTwigAdapter', function (): void {
	test('rss returns Markup so autoescape cannot mangle the document', function (): void {
		$out = feedAdapter()->rss(adapterMeta(), [['title' => 'x', 'link' => '/a', 'date' => '2026-08-26']]);

		expect($out)->toBeInstanceOf(Markup::class);
		expect((string)$out)->toContain('<rss');
		expect((string)$out)->toContain('Total CMS Releases');
	});

	test('atom returns Markup too', function (): void {
		$out = feedAdapter()->atom(
			adapterMeta(['self' => 'https://example.com/feed.atom']),
			[['title' => 'x', 'link' => '/a', 'date' => '2026-08-26']],
		);

		expect($out)->toBeInstanceOf(Markup::class);
		expect((string)$out)->toContain('http://www.w3.org/2005/Atom');
	});

	test('renders a feed with no items rather than requiring the argument', function (): void {
		// A collection can legitimately be empty, and a template should not
		// have to special-case that to avoid an error.
		expect((string)feedAdapter()->rss(adapterMeta()))->toContain('<channel>');
	});

	test('surfaces a missing required key as a DomainException', function (): void {
		$meta = adapterMeta();
		unset($meta['title']);

		feedAdapter()->rss($meta, []);
	})->throws(DomainException::class, 'title');
});
