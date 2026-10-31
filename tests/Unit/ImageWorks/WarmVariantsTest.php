<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Data\WarmVariants;

const WARM_GRID = [480, 768, 1024, 1440, 1920];

/** @param array<string,string|int> ...$sets */
function warmPairs(array $sets): array
{
	return array_map(static fn (array $s): string => $s['w'] . '.' . $s['fm'], $sets);
}

test('the grid matches the spec examples', function (): void {
	$variants = WarmVariants::fromOptions(WARM_GRID, ['avif', 'webp', 'native'], false, [], [], [], null);

	$photo = $variants->forImage(['name' => 'photo.jpg', 'width' => 1600, 'height' => 900]);
	expect($photo)->toHaveCount(15);
	expect(array_values(array_unique(array_column($photo, 'w'))))->toBe([480, 768, 1024, 1440, 1600]);
	expect(array_values(array_unique(array_column($photo, 'fm'))))->toBe(['avif', 'webp', 'jpg']);

	$logo = $variants->forImage(['name' => 'logo.png', 'width' => 1000, 'height' => 1000]);
	expect($logo)->toHaveCount(9);
	expect(array_values(array_unique(array_column($logo, 'w'))))->toBe([480, 768, 1000]);
	expect(array_values(array_unique(array_column($logo, 'fm'))))->toBe(['avif', 'webp', 'png']);
});

test('an explicit grid does not special-case gif and drops duplicate formats', function (): void {
	$sets = WarmVariants::fromOptions([480], ['avif', 'native', 'gif'], false, [], [], [], null)
		->forImage(['name' => 'a.gif', 'width' => 480, 'height' => 500]);

	expect(warmPairs($sets))->toBe(['480.avif', '480.gif']);
});

test('picture on a gif makes only the gif', function (): void {
	$sets = WarmVariants::fromOptions([], [], true, [], [], [], null)->forImage(['name' => 'a.gif', 'width' => 600, 'height' => 500]);

	expect(array_unique(array_column($sets, 'fm')))->toBe(['gif']);
});

test('picture on a gif gives one gif per width and no other format', function (): void {
	$sets = WarmVariants::fromOptions([], [], true, [], [], [], null)->forImage(['name' => 'a.gif', 'width' => 1000, 'height' => 500]);

	expect($sets)->not->toBeEmpty()
		->and(array_column($sets, 'fm'))->each->toBe('gif')
		->and(count($sets))->toBe(count(array_unique(array_column($sets, 'w'))));
});

test('picture with explicit formats uses the explicit grid rules', function (): void {
	$sets = WarmVariants::fromOptions([], ['webp'], true, [], [], [], null)->forImage(['name' => 'a.jpg', 'width' => 500, 'height' => 500]);

	expect(warmPairs($sets))->toBe(['480.webp', '500.webp']);
});

test('picture with explicit widths keeps the picture formats', function (): void {
	$sets = WarmVariants::fromOptions([480], [], true, [], [], [], null)->forImage(['name' => 'a.jpg', 'width' => 480, 'height' => 500]);

	expect(warmPairs($sets))->toBe(['480.avif', '480.webp', '480.jpg']);
});

test('widths alone default to native', function (): void {
	$sets = WarmVariants::fromOptions([480, 768], [], false, [], [], [], null)->forImage(['name' => 'a.png', 'width' => 2000, 'height' => 1000]);

	expect(warmPairs($sets))->toBe(['480.png', '768.png']);
});

test('a base params with a grid is combined with every cell and its w caps the widths', function (): void {
	$sets = WarmVariants::fromOptions([480, 768, 1024], [], false, ['h=600&fit=crop'], [], [], null)
		->forImage(['name' => 'a.jpg', 'width' => 3000, 'height' => 2000]);
	expect($sets)->toHaveCount(3); // only the widths asked for
	expect($sets[0])->toMatchArray(['w' => 480, 'fm' => 'jpg', 'h' => '600', 'fit' => 'crop']);

	$capped = WarmVariants::fromOptions([480, 768, 1024], [], false, ['w=800'], [], [], null)
		->forImage(['name' => 'a.jpg', 'width' => 3000, 'height' => 2000]);
	expect(array_column($capped, 'w'))->toBe([480, 768]);
});

test('widths alone add no full-size file and clamp a too-wide width to the image', function (): void {
	$sets = WarmVariants::fromOptions([480, 1920, 2400], ['webp'], false, [], [], [], null)
		->forImage(['name' => 'a.jpg', 'width' => 1600, 'height' => 900]);

	// 1920 and 2400 are both served as 1600 by the route, so they warm one file
	expect(warmPairs($sets))->toBe(['480.webp', '1600.webp']);
});

test('picture still adds the full-size width, as picture() renders it', function (): void {
	$sets = WarmVariants::fromOptions([480], ['webp'], true, [], [], [], null)
		->forImage(['name' => 'a.jpg', 'width' => 2000, 'height' => 1000]);

	expect(warmPairs($sets))->toBe(['480.webp', '2000.webp']);
});

test('formats alone is an error', function (): void {
	WarmVariants::fromOptions([], ['webp'], false, ['w=1'], [], [], null);
})->throws(InvalidArgumentException::class, '--formats');

test('fm in grid params is an error', function (): void {
	WarmVariants::fromOptions([480], [], false, ['fm=webp'], [], [], null);
})->throws(InvalidArgumentException::class, 'fm');

test('no options is an error', function (): void {
	WarmVariants::fromOptions([], [], false, [], [], [], null);
})->throws(InvalidArgumentException::class, 'Give at least one of --widths, --picture, --params, --preset or --url. Add --dry-run to see how many files that would make.');

test('an unknown format name is an error', function (): void {
	WarmVariants::fromOptions([480], ['bmp'], false, [], [], [], null);
})->throws(InvalidArgumentException::class, 'bmp');

test('exact params default fm to the native format', function (): void {
	$variants = WarmVariants::fromOptions([], [], false, ['w=800&h=600&fit=crop', 'w=100&fm=webp'], [], [], null);

	expect($variants->forImage(['name' => 'a.png']))->toBe([
		['w' => '800', 'h' => '600', 'fit' => 'crop', 'fm' => 'png'],
		['w' => '100', 'fm' => 'webp'],
	]);
});

test('a preset keeps p and no fm', function (): void {
	expect(WarmVariants::fromOptions([], [], false, [], ['medium'], [], null)->forImage(['name' => 'a.jpg']))->toBe([['p' => 'medium']]);
});

test('a url gives its params and extension', function (): void {
	$sets = WarmVariants::fromOptions([], [], false, [], [], ['https://site.test/imageworks/blog/a/image.webp?w=800&cache=abc'], null)->forImage(['name' => 'z.jpg']);

	expect($sets)->toHaveCount(1);
	expect($sets[0])->toMatchArray(['w' => '800', 'fm' => 'webp']);
});

test('a url with p keeps the preset format', function (): void {
	$sets = WarmVariants::fromOptions([], [], false, [], [], ['/imageworks/blog/a/image.webp?p=hero'], null)->forImage(['name' => 'z.jpg']);

	expect($sets)->toBe([['p' => 'hero']]);
});

test('a url without /imageworks/ is an error', function (): void {
	WarmVariants::fromOptions([], [], false, [], [], ['https://site.test/other/a.jpg?w=1'], null);
})->throws(InvalidArgumentException::class, '/imageworks/');

test('an unparseable url is an error', function (): void {
	WarmVariants::fromOptions([], [], false, [], [], ['http:///'], null);
})->throws(InvalidArgumentException::class);

test('sets keep the order grid, params, presets, urls and drop duplicates', function (): void {
	$variants = WarmVariants::fromOptions([480], [], false, [], ['small', 'small'], ['/imageworks/c/i/image.jpg?w=480'], null);
	// widths-only grid + url 480.jpg duplicate; presets deduped
	expect($variants->forImage(['name' => 'a.jpg', 'width' => 480, 'height' => 500]))->toBe([
		['w' => 480, 'fm' => 'jpg'],
		['p' => 'small'],
	]);
});

test('isEmpty is false once any variant source is given', function (): void {
	expect(WarmVariants::fromOptions([], [], false, [], ['x'], [], null)->isEmpty())->toBeFalse();
});

test('a grid with a preset base gives {p, w} sets with no fm, one per width', function (): void {
	$sets = WarmVariants::fromOptions([480, 768], [], true, ['p=medium'], [], [], null)->forImage(['name' => 'a.jpg', 'width' => 768, 'height' => 500]);

	expect($sets)->toBe([['p' => 'medium', 'w' => 480], ['p' => 'medium', 'w' => 768]]);
});

test('a url extension wins over a query fm', function (): void {
	$sets = WarmVariants::fromOptions([], [], false, [], [], ['/imageworks/c/i/image.jpg?fm=webp&w=5'], null)->forImage(['name' => 'z.png']);

	expect($sets)->toBe([['w' => '5', 'fm' => 'jpg']]);
});

test('a dynamic gallery url keeps its query and gets no fm', function (string $action): void {
	$sets = WarmVariants::fromOptions([], [], false, [], [], ["https://site.test/imageworks/blog/a/pics/{$action}?w=300&h=200"], null)->forImage(['name' => 'z.jpg']);

	expect($sets)->toBe([['w' => '300', 'h' => '200']]);
})->with(['first', 'last', 'featured']);

test('a random gallery url is still an error', function (): void {
	WarmVariants::fromOptions([], [], false, [], [], ['/imageworks/blog/a/pics/random?w=300'], null);
})->throws(InvalidArgumentException::class, 'image format');
