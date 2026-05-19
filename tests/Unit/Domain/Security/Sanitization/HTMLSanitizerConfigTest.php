<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Sanitization\HTMLSanitizerConfig;

describe('HTMLSanitizerConfig', function (): void {
	// -------------------------
	// Rich Content Allowed Tags
	// -------------------------

	test('HTMLSanitizerConfig → RICH_CONTENT_ALLOWED_TAGS contains expected HTML tags', function (): void {
		$allowedTags = HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS;

		expect($allowedTags)->toBeArray();
		expect($allowedTags)->not->toBeEmpty();

		// Basic text formatting
		expect($allowedTags)->toContain('p');
		expect($allowedTags)->toContain('br');
		expect($allowedTags)->toContain('strong');
		expect($allowedTags)->toContain('em');
		expect($allowedTags)->toContain('b');
		expect($allowedTags)->toContain('i');
		expect($allowedTags)->toContain('u');

		// Headings
		expect($allowedTags)->toContain('h1');
		expect($allowedTags)->toContain('h2');
		expect($allowedTags)->toContain('h3');
		expect($allowedTags)->toContain('h4');
		expect($allowedTags)->toContain('h5');
		expect($allowedTags)->toContain('h6');

		// Lists
		expect($allowedTags)->toContain('ul');
		expect($allowedTags)->toContain('ol');
		expect($allowedTags)->toContain('li');
		expect($allowedTags)->toContain('dl');
		expect($allowedTags)->toContain('dt');
		expect($allowedTags)->toContain('dd');

		// Links and media
		expect($allowedTags)->toContain('a');
		expect($allowedTags)->toContain('img');
		expect($allowedTags)->toContain('figure');
		expect($allowedTags)->toContain('figcaption');

		// Tables
		expect($allowedTags)->toContain('table');
		expect($allowedTags)->toContain('thead');
		expect($allowedTags)->toContain('tbody');
		expect($allowedTags)->toContain('tr');
		expect($allowedTags)->toContain('td');
		expect($allowedTags)->toContain('th');

		// Semantic HTML5
		expect($allowedTags)->toContain('section');
		expect($allowedTags)->toContain('article');
		expect($allowedTags)->toContain('aside');
		expect($allowedTags)->toContain('header');
		expect($allowedTags)->toContain('footer');
		expect($allowedTags)->toContain('main');
		expect($allowedTags)->toContain('nav');

		// Code elements
		expect($allowedTags)->toContain('code');
		expect($allowedTags)->toContain('pre');
		expect($allowedTags)->toContain('kbd');
		expect($allowedTags)->toContain('samp');
		expect($allowedTags)->toContain('var');

		// Media elements
		expect($allowedTags)->toContain('audio');
		expect($allowedTags)->toContain('video');
		expect($allowedTags)->toContain('source');
		expect($allowedTags)->toContain('track');
	});

	test('HTMLSanitizerConfig → RICH_CONTENT_ALLOWED_TAGS has expected count', function (): void {
		$allowedTags = HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS;

		// Should have a substantial number of allowed tags for rich content
		expect(count($allowedTags))->toBeGreaterThan(40);
		expect(count($allowedTags))->toBeLessThan(80); // Reasonable upper bound
	});

	test('HTMLSanitizerConfig → RICH_CONTENT_ALLOWED_TAGS contains no dangerous tags', function (): void {
		$allowedTags = HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS;

		// Should not contain dangerous script-related tags
		expect($allowedTags)->not->toContain('script');
		expect($allowedTags)->not->toContain('iframe');
		expect($allowedTags)->not->toContain('embed');
		expect($allowedTags)->not->toContain('object');
		expect($allowedTags)->not->toContain('applet');
		expect($allowedTags)->not->toContain('form');
		expect($allowedTags)->not->toContain('input');
		expect($allowedTags)->not->toContain('meta');
		expect($allowedTags)->not->toContain('link');
		expect($allowedTags)->not->toContain('style');
	});

	// -------------------------
	// Strict Content Allowed Tags
	// -------------------------

	test('HTMLSanitizerConfig → STRICT_CONTENT_ALLOWED_TAGS contains basic formatting', function (): void {
		$strictTags = HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS;

		expect($strictTags)->toBeArray();
		expect($strictTags)->not->toBeEmpty();

		// Basic elements only
		expect($strictTags)->toContain('p');
		expect($strictTags)->toContain('br');
		expect($strictTags)->toContain('strong');
		expect($strictTags)->toContain('b');
		expect($strictTags)->toContain('em');
		expect($strictTags)->toContain('i');
		expect($strictTags)->toContain('a');
		expect($strictTags)->toContain('ul');
		expect($strictTags)->toContain('ol');
		expect($strictTags)->toContain('li');
		expect($strictTags)->toContain('code');
	});

	test('HTMLSanitizerConfig → STRICT_CONTENT_ALLOWED_TAGS is subset of rich content tags', function (): void {
		$richTags   = HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS;
		$strictTags = HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS;

		// All strict tags should be present in rich tags
		foreach ($strictTags as $tag) {
			expect($richTags)->toContain($tag);
		}
	});

	test('HTMLSanitizerConfig → STRICT_CONTENT_ALLOWED_TAGS is more restrictive', function (): void {
		$richTags   = HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS;
		$strictTags = HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS;

		expect(count($strictTags))->toBeLessThan(count($richTags));
		expect(count($strictTags))->toBeLessThan(15); // Should be quite restrictive

		// Should not contain some tags that rich content allows
		expect($strictTags)->not->toContain('h1');
		expect($strictTags)->not->toContain('table');
		expect($strictTags)->not->toContain('img');
		expect($strictTags)->not->toContain('div');
		expect($strictTags)->not->toContain('section');
	});

	// -------------------------
	// Allowed CSS Properties
	// -------------------------

	test('HTMLSanitizerConfig → ALLOWED_CSS_PROPERTIES contains common CSS properties', function (): void {
		$cssProps = HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES;

		expect($cssProps)->toBeArray();
		expect($cssProps)->not->toBeEmpty();

		// Typography
		expect($cssProps)->toContain('color');
		expect($cssProps)->toContain('font-family');
		expect($cssProps)->toContain('font-size');
		expect($cssProps)->toContain('font-weight');
		expect($cssProps)->toContain('font-style');
		expect($cssProps)->toContain('text-align');
		expect($cssProps)->toContain('text-decoration');
		expect($cssProps)->toContain('line-height');

		// Layout
		expect($cssProps)->toContain('margin');
		expect($cssProps)->toContain('padding');
		expect($cssProps)->toContain('width');
		expect($cssProps)->toContain('height');
		expect($cssProps)->toContain('display');
		expect($cssProps)->toContain('position');

		// Margins and padding
		expect($cssProps)->toContain('margin-top');
		expect($cssProps)->toContain('margin-right');
		expect($cssProps)->toContain('margin-bottom');
		expect($cssProps)->toContain('margin-left');
		expect($cssProps)->toContain('padding-top');
		expect($cssProps)->toContain('padding-right');
		expect($cssProps)->toContain('padding-bottom');
		expect($cssProps)->toContain('padding-left');

		// Background and borders
		expect($cssProps)->toContain('background');
		expect($cssProps)->toContain('background-color');
		expect($cssProps)->toContain('border');
		expect($cssProps)->toContain('border-radius');

		// Lists
		expect($cssProps)->toContain('list-style');
		expect($cssProps)->toContain('list-style-type');

		// Tables
		expect($cssProps)->toContain('border-collapse');
		expect($cssProps)->toContain('border-spacing');

		// Misc
		expect($cssProps)->toContain('opacity');
		expect($cssProps)->toContain('visibility');
	});

	test('HTMLSanitizerConfig → ALLOWED_CSS_PROPERTIES excludes dangerous properties', function (): void {
		$cssProps = HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES;

		// Should not contain dangerous or advanced CSS
		expect($cssProps)->not->toContain('content');
		expect($cssProps)->not->toContain('expression');
		expect($cssProps)->not->toContain('-moz-binding');
		expect($cssProps)->not->toContain('behavior');
		expect($cssProps)->not->toContain('javascript');
		expect($cssProps)->not->toContain('vbscript');
		expect($cssProps)->not->toContain('@import');
		expect($cssProps)->not->toContain('filter');
	});

	test('HTMLSanitizerConfig → ALLOWED_CSS_PROPERTIES has reasonable count', function (): void {
		$cssProps = HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES;

		expect(count($cssProps))->toBeGreaterThan(30);
		expect(count($cssProps))->toBeLessThan(100); // Reasonable upper bound
	});

	// -------------------------
	// Allowed Iframe Domains
	// -------------------------

	test('HTMLSanitizerConfig → ALLOWED_IFRAME_DOMAINS contains trusted video platforms', function (): void {
		$domains = HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS;

		expect($domains)->toBeArray();
		expect($domains)->not->toBeEmpty();

		// Video platforms
		expect($domains)->toContain('www.youtube.com');
		expect($domains)->toContain('youtube.com');
		expect($domains)->toContain('player.vimeo.com');
		expect($domains)->toContain('vimeo.com');
		expect($domains)->toContain('www.dailymotion.com');
		expect($domains)->toContain('dailymotion.com');

		// Educational
		expect($domains)->toContain('embed.ted.com');
		expect($domains)->toContain('www.ted.com');

		// Code platforms
		expect($domains)->toContain('codepen.io');
		expect($domains)->toContain('jsfiddle.net');
		expect($domains)->toContain('github.com');
		expect($domains)->toContain('gist.github.com');
	});

	test('HTMLSanitizerConfig → ALLOWED_IFRAME_DOMAINS excludes suspicious domains', function (): void {
		$domains = HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS;

		// Should not contain suspicious or unknown domains
		expect($domains)->not->toContain('evil.com');
		expect($domains)->not->toContain('malware.net');
		expect($domains)->not->toContain('phishing.org');
		expect($domains)->not->toContain('localhost');
		expect($domains)->not->toContain('127.0.0.1');
		expect($domains)->not->toContain('0.0.0.0');
	});

	test('HTMLSanitizerConfig → ALLOWED_IFRAME_DOMAINS has reasonable count', function (): void {
		$domains = HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS;

		expect(count($domains))->toBeGreaterThan(5);
		expect(count($domains))->toBeLessThan(20); // Should be curated list
	});

	// -------------------------
	// Dangerous MIME Types
	// -------------------------

	test('HTMLSanitizerConfig → DANGEROUS_MIME_TYPES contains executable types', function (): void {
		$mimeTypes = HTMLSanitizerConfig::DANGEROUS_MIME_TYPES;

		expect($mimeTypes)->toBeArray();
		expect($mimeTypes)->not->toBeEmpty();

		// PHP executables
		expect($mimeTypes)->toContain('application/x-php');
		expect($mimeTypes)->toContain('application/x-httpd-php');
		expect($mimeTypes)->toContain('application/php');
		expect($mimeTypes)->toContain('text/x-php');

		// Shell scripts
		expect($mimeTypes)->toContain('application/x-sh');
		expect($mimeTypes)->toContain('application/x-csh');
		expect($mimeTypes)->toContain('text/x-shellscript');

		// Executables
		expect($mimeTypes)->toContain('application/x-executable');
		expect($mimeTypes)->toContain('application/x-msdownload');
		expect($mimeTypes)->toContain('application/x-msdos-program');
		expect($mimeTypes)->toContain('application/x-ms-dos-executable');
		expect($mimeTypes)->toContain('application/x-winexe');

		// JavaScript and scripting
		expect($mimeTypes)->toContain('application/x-javascript');
		expect($mimeTypes)->toContain('text/javascript');
		expect($mimeTypes)->toContain('application/javascript');
		expect($mimeTypes)->toContain('text/vbscript');
		expect($mimeTypes)->toContain('application/x-vbscript');
	});

	test('HTMLSanitizerConfig → DANGEROUS_MIME_TYPES excludes safe types', function (): void {
		$mimeTypes = HTMLSanitizerConfig::DANGEROUS_MIME_TYPES;

		// Should not contain safe image/document types
		expect($mimeTypes)->not->toContain('image/jpeg');
		expect($mimeTypes)->not->toContain('image/png');
		expect($mimeTypes)->not->toContain('image/gif');
		expect($mimeTypes)->not->toContain('text/plain');
		expect($mimeTypes)->not->toContain('text/html');
		expect($mimeTypes)->not->toContain('application/pdf');
		expect($mimeTypes)->not->toContain('text/css');
		expect($mimeTypes)->not->toContain('application/json');
	});

	test('HTMLSanitizerConfig → DANGEROUS_MIME_TYPES has reasonable count', function (): void {
		$mimeTypes = HTMLSanitizerConfig::DANGEROUS_MIME_TYPES;

		expect(count($mimeTypes))->toBeGreaterThan(10);
		expect(count($mimeTypes))->toBeLessThan(30); // Should be focused list
	});

	// -------------------------
	// Configuration Consistency
	// -------------------------

	test('HTMLSanitizerConfig → all constants are arrays', function (): void {
		expect(HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS)->toBeArray();
		expect(HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS)->toBeArray();
		expect(HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES)->toBeArray();
		expect(HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS)->toBeArray();
		expect(HTMLSanitizerConfig::DANGEROUS_MIME_TYPES)->toBeArray();
	});

	test('HTMLSanitizerConfig → arrays contain only strings', function (): void {
		$constants = [
			HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS,
			HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS,
			HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES,
			HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS,
			HTMLSanitizerConfig::DANGEROUS_MIME_TYPES,
		];

		foreach ($constants as $constantArray) {
			foreach ($constantArray as $item) {
				expect($item)->toBeString();
				expect($item)->not->toBeEmpty();
			}
		}
	});

	test('HTMLSanitizerConfig → arrays have no duplicates', function (): void {
		$constants = [
			'RICH_CONTENT_ALLOWED_TAGS'   => HTMLSanitizerConfig::RICH_CONTENT_ALLOWED_TAGS,
			'STRICT_CONTENT_ALLOWED_TAGS' => HTMLSanitizerConfig::STRICT_CONTENT_ALLOWED_TAGS,
			'ALLOWED_CSS_PROPERTIES'      => HTMLSanitizerConfig::ALLOWED_CSS_PROPERTIES,
			'ALLOWED_IFRAME_DOMAINS'      => HTMLSanitizerConfig::ALLOWED_IFRAME_DOMAINS,
			'DANGEROUS_MIME_TYPES'        => HTMLSanitizerConfig::DANGEROUS_MIME_TYPES,
		];

		foreach ($constants as $name => $constantArray) {
			$unique = array_unique($constantArray);
			expect(count($unique))->toBe(count($constantArray), "Constant {$name} should not have duplicates");
		}
	});
});
