<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Data\WarmVariants;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->render = $this->app->getContainer()->get(RenderTwigAdapter::class);
});

/**
 * The (w, fm) pairs of every ImageWorks URL in rendered markup.
 *
 * @return array<string,true>
 */
function renderedPairs(string $html): array
{
	preg_match_all('#/imageworks/[^\s"\',]+#', $html, $m);
	$pairs = [];
	foreach ($m[0] as $url) {
		$query = [];
		parse_str((string)parse_url(html_entity_decode($url), PHP_URL_QUERY), $query);
		$ext = pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
		$pairs[$query['w'] . '.' . $ext] = true;
	}

	return $pairs;
}

test('--picture matches what picture() renders', function (): void {
	$image  = ['name' => 'hero.jpg', 'alt' => 'x', 'width' => 2224, 'height' => 1000, 'hash' => 'h1', 'link' => '', 'mime' => 'image/jpeg', 'size' => 100];
	$html   = $this->render->picture(['id' => 'hero', 'image' => $image]);
	$warmed = array_flip(array_map(
		static fn (array $s): string => $s['w'] . '.' . $s['fm'],
		WarmVariants::fromOptions([], [], true, [], [], [], null)->forImage($image)
	));

	$rendered = renderedPairs($html);
	expect($rendered)->not->toBeEmpty();
	expect(array_diff_key($rendered, $warmed))->toBe([]);
});

test('--picture with a base matches picture() with the same transforms', function (): void {
	$image  = ['name' => 'hero.jpg', 'alt' => 'x', 'width' => 2224, 'height' => 1000, 'hash' => 'h1', 'link' => '', 'mime' => 'image/jpeg', 'size' => 100];
	$html   = $this->render->picture(['id' => 'hero', 'image' => $image], ['h' => 600, 'fit' => 'crop']);
	$warmed = array_flip(array_map(
		static fn (array $s): string => $s['w'] . '.' . $s['fm'],
		WarmVariants::fromOptions([], [], true, ['h=600&fit=crop'], [], [], null)->forImage($image)
	));

	$rendered = renderedPairs($html);
	expect($rendered)->not->toBeEmpty();
	expect(array_diff_key($rendered, $warmed))->toBe([]);
});

test('--picture with a preset base matches the widths picture() renders', function (): void {
	$image = ['name' => 'hero.jpg', 'alt' => 'x', 'width' => 2224, 'height' => 1000, 'hash' => 'h1', 'link' => '', 'mime' => 'image/jpeg', 'size' => 100];
	$html  = $this->render->picture(['id' => 'hero', 'image' => $image], ['p' => 'medium']);
	$sets  = WarmVariants::fromOptions([], [], true, ['p=medium'], [], [], null)->forImage($image);

	expect($sets)->not->toBeEmpty();
	foreach ($sets as $set) {
		expect($set)->toHaveKey('p')->and($set)->not->toHaveKey('fm');
	}
	$warmedWidths = array_column($sets, 'w');
	$rendered     = array_unique(array_map(static fn (string $k): int => (int)explode('.', $k)[0], array_keys(renderedPairs($html))));
	expect($rendered)->not->toBeEmpty();
	expect(array_diff($rendered, $warmedWidths))->toBe([]);
});
