<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

// ===== htmlencode =====

test('htmlencode converts characters to HTML entities', function (): void {
	$result = TotalCMSTwigFilters::htmlencode('user@example.com');
	expect($result)->not->toBe('user@example.com');
	expect($result)->toContain('&#');
	// Decoded back should equal the original
	expect(html_entity_decode($result))->toBe('user@example.com');
});

test('htmlencode handles empty string', function (): void {
	expect(TotalCMSTwigFilters::htmlencode(''))->toBe('');
});

test('htmlencode encodes special characters', function (): void {
	$result = TotalCMSTwigFilters::htmlencode('<script>alert("xss")</script>');
	expect($result)->not->toContain('<script>');
	expect($result)->toContain('&#');
});

// ===== htmldecode =====

test('htmldecode converts entities back to characters', function (): void {
	expect(TotalCMSTwigFilters::htmldecode('&amp;'))->toBe('&');
	expect(TotalCMSTwigFilters::htmldecode('&lt;div&gt;'))->toBe('<div>');
	expect(TotalCMSTwigFilters::htmldecode('&copy; 2024'))->toBe('© 2024');
});

test('htmldecode handles empty string', function (): void {
	expect(TotalCMSTwigFilters::htmldecode(''))->toBe('');
});

test('htmldecode handles string without entities', function (): void {
	expect(TotalCMSTwigFilters::htmldecode('Hello World'))->toBe('Hello World');
});

// ===== prefixSlug =====

test('prefixSlug slugifies and prefixes a string', function (): void {
	$result = TotalCMSTwigFilters::prefixSlug('Hello World', 'prefix-');
	expect($result)->toBe('prefix-hello-world');
});

test('prefixSlug handles array of strings', function (): void {
	$result = TotalCMSTwigFilters::prefixSlug(['Web Design', 'UI/UX'], 'tag-');
	expect($result)->toContain('tag-');
	$parts = explode(' ', $result);
	expect($parts)->toHaveCount(2);
});

test('prefixSlug handles custom separator', function (): void {
	$result = TotalCMSTwigFilters::prefixSlug(['php', 'javascript'], 'tag-', ', ');
	expect($result)->toContain(', ');
});

test('prefixSlug handles no prefix', function (): void {
	$result = TotalCMSTwigFilters::prefixSlug('Hello World');
	expect($result)->toBe('hello-world');
});

test('prefixSlug returns empty for null', function (): void {
	expect(TotalCMSTwigFilters::prefixSlug(null))->toBe('');
});

test('prefixSlug returns empty for empty string', function (): void {
	expect(TotalCMSTwigFilters::prefixSlug(''))->toBe('');
});

test('prefixSlug returns empty for empty array', function (): void {
	expect(TotalCMSTwigFilters::prefixSlug([]))->toBe('');
});

test('prefixSlug filters out empty items in array', function (): void {
	$result = TotalCMSTwigFilters::prefixSlug(['foo', '', 'bar'], 'x-');
	$parts = explode(' ', $result);
	expect($parts)->toHaveCount(2);
});

// ===== unique =====

test('unique removes duplicate values', function (): void {
	$result = TotalCMSTwigFilters::unique(['php', 'javascript', 'php', 'css', 'javascript']);
	expect(array_values($result))->toBe(['php', 'javascript', 'css']);
});

test('unique handles empty array', function (): void {
	expect(TotalCMSTwigFilters::unique([]))->toBe([]);
});

test('unique handles array with no duplicates', function (): void {
	$result = TotalCMSTwigFilters::unique(['a', 'b', 'c']);
	expect(array_values($result))->toBe(['a', 'b', 'c']);
});

test('unique handles single element array', function (): void {
	expect(TotalCMSTwigFilters::unique(['only']))->toBe(['only']);
});

// ===== filesize =====

test('filesize formats bytes', function (): void {
	expect(TotalCMSTwigFilters::filesize(0))->toBe('0 B');
	expect(TotalCMSTwigFilters::filesize(500))->toBe('500 B');
});

test('filesize formats kilobytes', function (): void {
	expect(TotalCMSTwigFilters::filesize(1000))->toBe('1 KB');
	expect(TotalCMSTwigFilters::filesize(1500))->toBe('2 KB');
});

test('filesize formats megabytes', function (): void {
	expect(TotalCMSTwigFilters::filesize(1000000))->toBe('1.0 MB');
	expect(TotalCMSTwigFilters::filesize(1500000))->toBe('1.5 MB');
});

test('filesize formats gigabytes', function (): void {
	expect(TotalCMSTwigFilters::filesize(1000000000))->toBe('1.0 GB');
});

test('filesize supports custom decimal places', function (): void {
	expect(TotalCMSTwigFilters::filesize(1500000, 2))->toBe('1.50 MB');
	expect(TotalCMSTwigFilters::filesize(1500000, 0))->toBe('2 MB');
});

test('filesize returns 0 B for invalid input', function (): void {
	expect(TotalCMSTwigFilters::filesize('not a number'))->toBe('0 B');
	expect(TotalCMSTwigFilters::filesize(-100))->toBe('0 B');
});

test('filesize does not show decimals for bytes and KB', function (): void {
	expect(TotalCMSTwigFilters::filesize(999))->toBe('999 B');
	expect(TotalCMSTwigFilters::filesize(5500))->toBe('6 KB');
});

// ===== markdownInline =====

test('markdownInline converts bold', function (): void {
	$result = TotalCMSTwigFilters::markdownInline('This is **bold** text');
	expect($result)->toContain('<strong>bold</strong>');
	expect($result)->not->toContain('<p>');
});

test('markdownInline converts italic', function (): void {
	$result = TotalCMSTwigFilters::markdownInline('This is *italic* text');
	expect($result)->toContain('<em>italic</em>');
});

test('markdownInline converts links', function (): void {
	$result = TotalCMSTwigFilters::markdownInline('[example](https://example.com)');
	expect($result)->toContain('<a href="https://example.com">example</a>');
});

test('markdownInline converts inline code', function (): void {
	$result = TotalCMSTwigFilters::markdownInline('Use `code` here');
	expect($result)->toContain('<code>code</code>');
});

test('markdownInline does not produce block elements', function (): void {
	$result = TotalCMSTwigFilters::markdownInline('Just text');
	expect($result)->not->toContain('<p>');
	expect($result)->not->toContain('<h1>');
	expect($result)->not->toContain('<div>');
});

test('markdownInline handles empty string', function (): void {
	expect(TotalCMSTwigFilters::markdownInline(''))->toBe('');
});

test('markdownInline handles non-string input', function (): void {
	$result = TotalCMSTwigFilters::markdownInline(42);
	expect($result)->toBe('42');
});

test('markdownInline handles combined styles', function (): void {
	$result = TotalCMSTwigFilters::markdownInline('**bold** with *italic*');
	expect($result)->toContain('<strong>bold</strong>');
	expect($result)->toContain('<em>italic</em>');
});
