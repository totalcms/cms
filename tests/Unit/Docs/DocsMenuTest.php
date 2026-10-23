<?php

declare(strict_types=1);

// The docs sidebar menu sorts itself: a group or subgroup carrying
// `'sort' => 'alpha'` lists its pages alphabetically, after any entries
// marked `'pinned' => true`, which keep their written order at the top.
// Groups without the flag keep the order they are written in. The sort
// happens inside menu.php so every consumer — the admin viewer, the
// search-index builder, the validator and the docs site's sidebar
// builder — reads the same order from the one file.

/**
 * @param array<string,mixed> $menu
 *
 * @return list<array{context:string, sub:list<array<string,mixed>>}>
 */
function docsMenuTestSortedLists(array $menu): array
{
	$lists = [];
	foreach ($menu as $group) {
		if (($group['sort'] ?? null) === 'alpha' && is_array($group['sub'] ?? null)) {
			$lists[] = ['context' => $group['title'], 'sub' => $group['sub']];
		}
		foreach ($group['groups'] ?? [] as $subgroup) {
			if (($subgroup['sort'] ?? null) === 'alpha' && is_array($subgroup['sub'] ?? null)) {
				$lists[] = ['context' => $group['title'] . ' / ' . $subgroup['title'], 'sub' => $subgroup['sub']];
			}
		}
	}

	return $lists;
}

/** @return array<string,mixed> */
function docsMenuTestLoad(): array
{
	return require dirname(__DIR__, 3) . '/resources/docs/menu.php';
}

test('every sorted group lists its pages alphabetically after the pinned ones', function (): void {
	$lists = docsMenuTestSortedLists(docsMenuTestLoad());
	expect($lists)->not->toBeEmpty();

	foreach ($lists as ['context' => $context, 'sub' => $sub]) {
		$titles = array_column($sub, 'title');
		$pinned = array_values(array_filter($sub, static fn (array $e): bool => ($e['pinned'] ?? false) === true));
		$rest   = array_slice($titles, count($pinned));

		expect(array_column(array_slice($sub, 0, count($pinned)), 'pinned'))
			->toBe(array_fill(0, count($pinned), true), "$context: pinned entries come first");

		$expected = $rest;
		usort($expected, static fn (string $a, string $b): int => strnatcmp(mb_strtolower($a), mb_strtolower($b)));
		expect($rest)->toBe($expected, "$context is not in alphabetical order");

		expect(array_unique(array_map('mb_strtolower', $titles)))
			->toHaveCount(count($titles), "$context has duplicate titles, which makes the sort ambiguous");
	}
});

test('Operations is a sorted reference list', function (): void {
	$operations = array_values(array_filter(docsMenuTestLoad(), static fn (array $g): bool => $g['title'] === 'Operations'))[0];

	expect($operations['sort'] ?? null)->toBe('alpha');
	expect(array_slice(array_column($operations['sub'], 'title'), 0, 3))->toBe(['Activity Log', 'Apache', 'Backups']);
});

test('Field Types keeps its two intro pages first, then sorts the rest', function (): void {
	$fields = array_values(array_filter(docsMenuTestLoad(), static fn (array $g): bool => $g['title'] === 'Fields'))[0];
	$types  = array_values(array_filter($fields['groups'], static fn (array $g): bool => $g['title'] === 'Field Types'))[0];
	$titles = array_column($types['sub'], 'title');

	expect(array_slice($titles, 0, 3))->toBe(['Choosing a Field', 'All Fields', 'Card']);
});

test('Bundled Extensions keeps its overview first', function (): void {
	$ext     = array_values(array_filter(docsMenuTestLoad(), static fn (array $g): bool => $g['title'] === 'Extensions & CLI'))[0];
	$bundled = array_values(array_filter($ext['groups'], static fn (array $g): bool => $g['title'] === 'Bundled Extensions'))[0];

	expect(array_column($bundled['sub'], 'title')[0])->toBe('Bundled Overview');
	expect($bundled['sort'] ?? null)->toBe('alpha');
});

test('groups without the flag keep their written order', function (): void {
	$start = array_values(array_filter(docsMenuTestLoad(), static fn (array $g): bool => $g['title'] === 'Get Started'))[0];

	expect($start['sort'] ?? null)->toBeNull();
	expect(array_column($start['sub'], 'title'))->toBe(['Welcome', 'Requirements', 'Installation', 'Your First Site']);
});
