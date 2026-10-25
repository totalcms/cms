<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\BadgeTwigAdapter;

describe('generic badges', function (): void {
	test('pill links home and serves the light image with its size', function (): void {
		$html = (new BadgeTwigAdapter())->pill();

		expect($html)->toBe(
			'<a href="https://totalcms.co/?mtm_campaign=badge&amp;mtm_content=pill&amp;mtm_medium=twig">'
			. '<img src="https://totalcms.co/badges/pill-light.svg" alt="Built with Total CMS" width="167" height="32"></a>'
		);
	});

	test('stacked takes the dark theme', function (): void {
		$html = (new BadgeTwigAdapter())->stacked(['theme' => 'dark']);

		expect($html)->toContain('src="https://totalcms.co/badges/stacked-dark.svg"')
			->toContain('width="134" height="46"')
			->toContain('mtm_content=stacked');
	});

	test('auto theme wraps the image in a picture with a dark source', function (): void {
		$html = (new BadgeTwigAdapter())->pill(['theme' => 'auto']);

		expect($html)->toContain('<picture><source srcset="https://totalcms.co/badges/pill-dark.svg" media="(prefers-color-scheme: dark)">')
			->toContain('<img src="https://totalcms.co/badges/pill-light.svg"')
			->toContain('</picture></a>');
	});

	test('an unknown theme falls back to light', function (): void {
		expect((new BadgeTwigAdapter())->pill(['theme' => 'neon']))
			->toContain('pill-light.svg')
			->not->toContain('neon');
	});
});

describe('text badge', function (): void {
	test('is inline text in the host colour with the gradient flame', function (): void {
		$html = (new BadgeTwigAdapter())->text();

		expect($html)->toStartWith('<a href="https://totalcms.co/?mtm_campaign=badge&amp;mtm_content=text&amp;mtm_medium=twig" style="')
			->toContain('color:inherit')
			->toContain('url(#tcms-badge-a)')
			->toEndWith('Powered by Total CMS</a>');
	});

	test('mono draws the flame in currentColor with no gradients', function (): void {
		$html = (new BadgeTwigAdapter())->text(['theme' => 'mono']);

		expect($html)->toContain('fill="currentColor"')
			->not->toContain('<defs>')
			->not->toContain('url(#');
	});
});

describe('member badges', function (): void {
	test('earned links through /badges/{handle} and sets only a height', function (): void {
		$html = (new BadgeTwigAdapter())->earned('joeworkman');

		expect($html)->toBe(
			'<a href="https://totalcms.co/badges/joeworkman?mtm_campaign=badge&amp;mtm_source=joeworkman&amp;mtm_content=earned&amp;mtm_medium=twig" rel="me">'
			. '<img src="https://totalcms.co/@joeworkman/badge.svg?style=earned&amp;theme=light" alt="@joeworkman on Total CMS" height="32"></a>'
		);
	});

	test('profile is the card style at 52px', function (): void {
		$html = (new BadgeTwigAdapter())->profile('joeworkman', ['theme' => 'dark']);

		expect($html)->toContain('/@joeworkman/badge.svg?style=card&amp;theme=dark')
			->toContain('height="52"')
			->not->toContain('width=');
	});

	test('auto theme serves both member images', function (): void {
		$html = (new BadgeTwigAdapter())->earned('joeworkman', ['theme' => 'auto']);

		expect($html)->toContain('srcset="https://totalcms.co/@joeworkman/badge.svg?style=earned&amp;theme=dark"')
			->toContain('src="https://totalcms.co/@joeworkman/badge.svg?style=earned&amp;theme=light"');
	});

	test('the link has rel="me" by default', function (string $method): void {
		$html = (new BadgeTwigAdapter())->{$method}('joeworkman');

		expect($html)->toStartWith('<a href="https://totalcms.co/badges/joeworkman?')
			->toContain('" rel="me"><img');
	})->with(['profile', 'earned']);

	test('me: false drops the rel', function (string $method): void {
		expect((new BadgeTwigAdapter())->{$method}('joeworkman', ['me' => false]))->not->toContain('rel=')
			->and((new BadgeTwigAdapter())->{$method}('joeworkman', ['me' => true]))->toContain('" rel="me"><img');
	})->with(['profile', 'earned']);

	test('an invalid handle never claims rel="me"', function (): void {
		expect((new BadgeTwigAdapter())->earned('"x', ['me' => true]))->not->toContain('rel=');
	});

	test('a handle is trimmed and lowercased', function (): void {
		expect((new BadgeTwigAdapter())->earned('  JoeWorkman '))->toContain('/@joeworkman/badge.svg');
	});

	test('an invalid handle gives the pill instead of a broken badge', function (string $handle): void {
		$html = (new BadgeTwigAdapter())->earned($handle, ['theme' => 'dark']);

		expect($html)->toContain('pill-dark.svg')
			->not->toContain('/@')
			->not->toContain('<script');
	})->with(['', 'ab', '"><script>alert(1)</script>', '../admin', '-joe', str_repeat('a', 31)]);
});

describe('built-by badges', function (): void {
	test('builtBy links through /badges/{handle} and sets only a height', function (): void {
		$html = (new BadgeTwigAdapter())->builtBy('joeworkman');

		expect($html)->toBe(
			'<a href="https://totalcms.co/badges/joeworkman?mtm_campaign=badge&amp;mtm_source=joeworkman&amp;mtm_content=builtby&amp;mtm_medium=twig">'
			. '<img src="https://totalcms.co/@joeworkman/badge.svg?style=builtby&amp;theme=light" alt="Built by @joeworkman" height="32"></a>'
		);
	});

	test('builtByText is the 16px image with its own campaign content', function (): void {
		$html = (new BadgeTwigAdapter())->builtByText('joeworkman');

		expect($html)->toBe(
			'<a href="https://totalcms.co/badges/joeworkman?mtm_campaign=badge&amp;mtm_source=joeworkman&amp;mtm_content=builtbytext&amp;mtm_medium=twig">'
			. '<img src="https://totalcms.co/@joeworkman/badge.svg?style=builtbytext&amp;theme=light" alt="Built by @joeworkman" height="16"></a>'
		);
	});

	test('byName: true credits the name', function (string $method, string $style): void {
		expect((new BadgeTwigAdapter())->{$method}('joeworkman', ['byName' => true]))->toContain("style={$style}&amp;theme=light&amp;by=name\"")
			->and((new BadgeTwigAdapter())->{$method}('joeworkman', ['byName' => false]))->not->toContain('by=')
			->and((new BadgeTwigAdapter())->{$method}('joeworkman', ['byName' => 'yes']))->not->toContain('by=');
	})->with([['builtBy', 'builtby'], ['builtByText', 'builtbytext']]);

	test('theme and byName combine', function (string $method, string $style): void {
		expect((new BadgeTwigAdapter())->{$method}('joeworkman', ['theme' => 'dark']))->toContain("style={$style}&amp;theme=dark\"")
			->and((new BadgeTwigAdapter())->{$method}('joeworkman', ['theme' => 'dark', 'byName' => true]))->toContain("style={$style}&amp;theme=dark&amp;by=name\"");
	})->with([['builtBy', 'builtby'], ['builtByText', 'builtbytext']]);

	test('auto serves both images', function (): void {
		$html = (new BadgeTwigAdapter())->builtByText('joeworkman', ['theme' => 'auto', 'byName' => true]);

		expect($html)->toContain('srcset="https://totalcms.co/@joeworkman/badge.svg?style=builtbytext&amp;theme=dark&amp;by=name"')
			->toContain('src="https://totalcms.co/@joeworkman/badge.svg?style=builtbytext&amp;theme=light&amp;by=name"');
	});

	test('builtByText mono passes the colour as 6 lowercase digits', function (mixed $color, string $query): void {
		expect((new BadgeTwigAdapter())->builtByText('joeworkman', ['theme' => 'mono', 'color' => $color, 'byName' => true]))
			->toContain('src="https://totalcms.co/@joeworkman/badge.svg?style=builtbytext&amp;theme=mono&amp;by=name' . $query . '"')
			->not->toContain('<picture>');
	})->with([
		['#CFE3D6', '&amp;color=cfe3d6'],
		['5b6170', '&amp;color=5b6170'],
		['#AbC', '&amp;color=aabbcc'],
		['#cfe3d', ''],
		['red', ''],
		['#cfe3d6"><script>', ''],
		[null, ''],
	]);

	test('color is ignored outside mono, and mono only on builtByText', function (): void {
		$adapter = new BadgeTwigAdapter();

		expect($adapter->builtByText('joeworkman', ['theme' => 'dark', 'color' => '#cfe3d6']))->not->toContain('color=')
			->and($adapter->builtBy('joeworkman', ['theme' => 'mono', 'color' => '#cfe3d6']))->toContain('style=builtby&amp;theme=light"');
	});

	test('never claims rel="me", even when asked', function (string $method): void {
		expect((new BadgeTwigAdapter())->{$method}('joeworkman', ['me' => true]))->not->toContain('rel=');
	})->with(['builtBy', 'builtByText']);

	test('an invalid handle gives the pill', function (string $method): void {
		expect((new BadgeTwigAdapter())->{$method}('"><script>', ['theme' => 'dark']))->toContain('pill-dark.svg')
			->not->toContain('<script')
			->not->toContain('/@');
	})->with(['builtBy', 'builtByText']);
});
