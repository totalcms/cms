<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Data\SeoSettings;

describe('SeoSettings', function (): void {
	test('defaults when the section is empty', function (): void {
		$s = SeoSettings::fromArray([], 'example.com');
		expect($s->siteName)->toBe('')
			->and($s->baseUrl)->toBe('https://example.com')
			->and($s->titleTemplate)->toBe('${title} | ${site}')
			->and($s->socialTitleTemplate)->toBe('${title}')
			->and($s->defaultDescription)->toBe('')
			->and($s->defaultSocialDescription)->toBe('')
			->and($s->emitJsonLd)->toBeTrue()
			->and($s->emitSocial)->toBeTrue()
			->and($s->emitGenerator)->toBeTrue()
			->and($s->sameAs)->toBe([])
			->and($s->metaTags)->toBe('');
	});

	test('normalises the values it is given', function (): void {
		$s = SeoSettings::fromArray([
			'siteName'           => ' Joe\'s Bistro ',
			'baseUrl'            => 'https://joesbistro.com/',
			'twitterHandle'      => 'joesbistro',
			'sameAs'             => "https://x.com/joesbistro\n\nhttps://instagram.com/joesbistro",
			'emitJsonLd'         => false,
			'emitGenerator'      => '0',
			'metaTags'           => "  <meta name=\"google-site-verification\" content=\"abc\">\n<script>x()</script>\n ",
		], 'ignored.test');
		expect($s->siteName)->toBe("Joe's Bistro")
			->and($s->baseUrl)->toBe('https://joesbistro.com')
			->and($s->twitterHandle)->toBe('@joesbistro')
			->and($s->sameAs)->toBe(['https://x.com/joesbistro', 'https://instagram.com/joesbistro'])
			->and($s->emitJsonLd)->toBeFalse()
			->and($s->emitGenerator)->toBeFalse()
			// Trimmed, otherwise verbatim: whatever an operator pastes is emitted as given.
			->and($s->metaTags)->toBe("<meta name=\"google-site-verification\" content=\"abc\">\n<script>x()</script>");
	});

	test('icons and theme color default to nothing', function (): void {
		$s = SeoSettings::fromArray([], 'example.com');
		expect($s->iconSvg)->toBe('')
			->and($s->icon32)->toBe('')
			->and($s->icon192)->toBe('')
			->and($s->icon512)->toBe('')
			->and($s->touchIcon)->toBe('')
			->and($s->themeColor)->toBe('')
			->and($s->manifest)->toBe('')
			->and($s->hasIcons())->toBeFalse()
			->and($s->hasIconSlice())->toBeFalse();

		// A manifest or a theme color alone still gives the slice something to print.
		expect(SeoSettings::fromArray(['manifestUrl' => '/manifest.webmanifest'], 'x')->hasIconSlice())->toBeTrue();
	});

	test('reads the resolved icon URLs and the theme color hex', function (): void {
		// The loader resolves the record's image/file properties to URLs under
		// these keys before handing the array over; the color field stores an
		// object with the hex alongside its OKLCH coordinates.
		$s = SeoSettings::fromArray([
			'iconSvgUrl'   => '/favicon.svg',
			'icon32'       => '/imageworks/seo-site/seo-site/icon.png?w=32',
			'icon192'      => '/imageworks/seo-site/seo-site/icon.png?w=192',
			'icon512'      => '/imageworks/seo-site/seo-site/icon.png?w=512',
			'touchIcon180' => '/imageworks/seo-site/seo-site/touchIcon.png?w=180',
			'themeColor'   => ['hex' => '#F17724', 'oklch' => ['l' => 70, 'c' => 0.19, 'h' => 50]],
		], 'example.com');
		expect($s->iconSvg)->toBe('/favicon.svg')
			->and($s->icon32)->toContain('w=32')
			->and($s->touchIcon)->toContain('touchIcon')
			->and($s->themeColor)->toBe('#f17724')
			->and($s->hasIcons())->toBeTrue();

		expect(SeoSettings::fromArray(['themeColor' => '#ABC'], 'x')->themeColor)->toBe('#abc');
		expect(SeoSettings::fromArray(['themeColor' => ['hex' => '']], 'x')->themeColor)->toBe('');
	});

	test('the touch icon cut from the Icon is inset on a theme-colored tile', function (): void {
		// 140px of icon plus a 20px border in the same color on every side
		// grows the canvas back to the 180px tile, with the mark inset.
		$padded = static fn (string $hex): array => ['w' => 140, 'h' => 140, 'fit' => 'crop-focalpoint', 'fm' => 'png', 'bg' => $hex, 'border' => "20,{$hex},expand"];
		expect(SeoSettings::touchIconFromIcon('#090E1B'))->toBe($padded('090e1b'));
		expect(SeoSettings::touchIconFromIcon(['hex' => '#abc']))->toBe($padded('aabbcc'));
		// No theme color: black, which is what iOS paints behind transparency anyway.
		expect(SeoSettings::touchIconFromIcon(''))->toBe($padded('000000'));
		expect(SeoSettings::touchIconFromIcon(null))->toBe($padded('000000'));
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
