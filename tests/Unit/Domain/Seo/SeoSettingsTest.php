<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Data\SeoSettings;

describe('SeoSettings', function (): void {
	test('defaults when the section is empty', function (): void {
		$s = SeoSettings::fromArray([], 'example.com');
		expect($s->siteName)->toBe('')
			->and($s->baseUrl)->toBe('https://example.com')
			->and($s->titleTemplate)->toBe('{title} | {site}')
			->and($s->titleSeparator)->toBe('|')
			->and($s->emitJsonLd)->toBeTrue()
			->and($s->emitSocial)->toBeTrue()
			->and($s->sameAs)->toBe([])
			->and($s->verification)->toBe(['google' => '', 'bing' => '', 'pinterest' => '']);
	});

	test('normalises the values it is given', function (): void {
		$s = SeoSettings::fromArray([
			'siteName'          => ' Joe\'s Bistro ',
			'baseUrl'           => 'https://joesbistro.com/',
			'twitterHandle'     => 'joesbistro',
			'sameAs'            => "https://x.com/joesbistro\n\nhttps://instagram.com/joesbistro",
			'emitJsonLd'        => false,
			'googleVerification' => 'abc',
		], 'ignored.test');
		expect($s->siteName)->toBe("Joe's Bistro")
			->and($s->baseUrl)->toBe('https://joesbistro.com')
			->and($s->twitterHandle)->toBe('@joesbistro')
			->and($s->sameAs)->toBe(['https://x.com/joesbistro', 'https://instagram.com/joesbistro'])
			->and($s->emitJsonLd)->toBeFalse()
			->and($s->verification['google'])->toBe('abc')
			->and($s->verification['bing'])->toBe('');
	});

	test('a base URL without a scheme gets https', function (): void {
		expect(SeoSettings::fromArray(['baseUrl' => 'joesbistro.com'], 'x')->baseUrl)->toBe('https://joesbistro.com');
	});

	test('absolute() prefixes relative paths and leaves everything else alone', function (): void {
		// The single absolutizer: canonical URLs, the site's default share image
		// and every per-object image go through it.
		$s = SeoSettings::fromArray([], 'example.com');
		expect($s->absolute(''))->toBe('')
			->and($s->absolute('/x.jpg'))->toBe('https://example.com/x.jpg')
			->and($s->absolute('x.jpg'))->toBe('https://example.com/x.jpg')
			->and($s->absolute('https://cdn/x.jpg'))->toBe('https://cdn/x.jpg');
	});
});
