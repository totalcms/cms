<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Support\Config;

// The channel image the /feed/rss endpoint always offered now comes from the
// same meta as everything else, so templates get it too.
function imageFeedWriter(): FeedWriter
{
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = 'example.com';

	return new FeedWriter($config);
}

test('a bare image URL borrows the feed title and link', function (): void {
	$xml = imageFeedWriter()->write([
		'title' => 'Site', 'link' => '/blog/', 'description' => 'd', 'image' => '/logo.png',
	], [], 'rss');

	expect($xml)->toContain('<url>https://example.com/logo.png</url>')
		->and($xml)->toContain("<image>\n      <url>https://example.com/logo.png</url>\n      <title>Site</title>\n      <link>https://example.com/blog/</link>");
});

test('an image hash keeps its own title and link', function (): void {
	$xml = imageFeedWriter()->write([
		'title' => 'Site', 'link' => '/', 'description' => 'd',
		'image' => ['url' => 'https://cdn.example.com/a.png', 'title' => 'Cover', 'link' => 'https://elsewhere.example/'],
	], [], 'rss');

	expect($xml)->toContain('<title>Cover</title>')
		->and($xml)->toContain('<link>https://elsewhere.example/</link>');
});

test('no image key means no image element', function (): void {
	expect(imageFeedWriter()->write(['title' => 'S', 'link' => '/', 'description' => 'd'], [], 'rss'))->not->toContain('<image>');
});
