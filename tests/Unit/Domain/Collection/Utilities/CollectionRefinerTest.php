<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Utilities\CollectionRefiner;

/*
|--------------------------------------------------------------------------
| CollectionRefiner
|--------------------------------------------------------------------------
|
| This is the query engine behind `cms.collection.objects()` / the Twig
| `filterCollection` filter — the single most-travelled read path on a Total
| CMS site. Every one of these tests exists because a regression here does not
| throw: it silently returns the WRONG SET of rows to a visitor. Either the
| page renders too little (content vanishes) or too much (drafts, unpublished
| or member-only rows leak to anonymous traffic).
|
| Note on scope: sorting lives in CollectionSorter and draft/access gating for
| the REST + MCP paths lives in ObjectFilter / PersonaContext. This class does
| filtering only, so the "drafts" tests here cover the Twig-authored rule
| shapes an integrator writes by hand — which is exactly where a leak is most
| likely to be introduced by accident.
|
| Existing coverage lives in tests/Unit/CollectionRefinerAndLogicTest.php,
| which already covers filterByArrayRule() OR/AND logic. That is deliberately
| not repeated here.
|
*/

/**
 * Canonical fixture — shaped like a small blog collection.
 *
 * Fixed dates are used wherever the assertion must not depend on when the
 * suite runs; relative dates are used only in the today/± days tests.
 *
 * @return array<int,array<string,mixed>>
 */
function collectionRefinerRows(): array
{
	return [
		[
			'id'       => 'post-1',
			'title'    => 'Alpha Release',
			'category' => 'news',
			'draft'    => false,
			'views'    => 10,
			'price'    => '25',
			'date'     => '2000-01-15', // a Saturday, safely in the past
			'tags'     => ['php', 'web'],
			'meta'     => ['author' => 'joe', 'editors' => ['kim', 'lee']],
			'summary'  => 'Short',
		],
		[
			'id'       => 'post-2',
			'title'    => 'beta release',
			'category' => 'news',
			'draft'    => true,
			'views'    => 5,
			'price'    => '150',
			'date'     => '2030-06-03', // safely in the future
			'tags'     => ['web'],
			'meta'     => ['author' => 'sam'],
			'summary'  => 'a much longer summary than any of the others here',
		],
		[
			'id'       => 'post-3',
			'title'    => 'Gamma Notes',
			'category' => 'guide',
			'draft'    => false,
			'views'    => 0,
			'price'    => '75',
			'date'     => '2020-06-15', // a Monday
			'tags'     => [],
			'meta'     => ['author' => 'joe'],
			'summary'  => '',
		],
	];
}

/** @param array<int,array<string,mixed>>|null $rows */
function collectionRefinerFor(?array $rows = null): CollectionRefiner
{
	return new CollectionRefiner($rows ?? collectionRefinerRows());
}

/**
 * Run rules and return just the ids, re-indexed — filter() preserves the
 * original array keys, so tests compare ids rather than whole arrays.
 *
 * @param array<int,array<string,mixed>> $rules
 * @param array<int,array<string,mixed>>|null $rows
 *
 * @return array<int,string>
 */
function collectionRefinerFilterIds(array $rules, ?array $rows = null): array
{
	$result = collectionRefinerFor($rows)->filter($rules);

	return array_values(array_map(static fn (array $row): string => (string)$row['id'], $result));
}

/**
 * Shorthand for the single-rule case, which is the overwhelming majority of
 * real-world usage.
 *
 * @param array<int,array<string,mixed>>|null $rows
 *
 * @return array<int,string>
 */
function collectionRefinerIdsWhere(string $property, string $operator, mixed $value = null, ?array $rows = null): array
{
	$rule = ['property' => $property, 'operator' => $operator];
	if ($value !== null) {
		$rule['value'] = $value;
	}

	return collectionRefinerFilterIds([$rule], $rows);
}

describe('CollectionRefiner :: rule dispatch', function (): void {
	it('returns the collection untouched when there are no rules', function (): void {
		// A page that renders a collection with no filters must show everything.
		// An over-eager early-exit here would blank out every unfiltered listing
		// on the site.
		expect(collectionRefinerFor()->filter([]))->toBe(collectionRefinerRows());
	});

	it('returns an empty array when the collection itself is empty', function (): void {
		// Guards the empty-collection short circuit: a brand-new collection must
		// not explode the template that lists it.
		expect(collectionRefinerFor([])->filter([['property' => 'title', 'value' => 'x']]))->toBe([]);
	});

	it('skips a rule with no property instead of throwing', function (): void {
		// Rules come from hand-written Twig. A typo'd rule key must degrade to
		// "no filter", not fatal the whole page render.
		expect(collectionRefinerFilterIds([['operator' => 'equal', 'value' => 'news']]))
			->toBe(['post-1', 'post-2', 'post-3']);
	});

	it('defaults the operator to equal when none is given', function (): void {
		expect(collectionRefinerFilterIds([['property' => 'category', 'value' => 'guide']]))
			->toBe(['post-3']);
	});

	it('applies multiple rules as AND, narrowing the set with each rule', function (): void {
		// Real listings stack rules ("published news with few views"). If rules
		// were ever OR'd, a draft filter combined with anything else would stop
		// excluding drafts.
		expect(collectionRefinerFilterIds([
			['property' => 'category', 'operator' => 'equal', 'value' => 'news'],
			['property' => 'draft', 'operator' => 'isfalse'],
		]))->toBe(['post-1']);
	});

	it('stops cleanly once a rule has eliminated every row', function (): void {
		// The early-exit path inside the rule loop: later rules must not
		// resurrect rows an earlier rule removed.
		expect(collectionRefinerFilterIds([
			['property' => 'category', 'operator' => 'equal', 'value' => 'nothing-matches'],
			['property' => 'draft', 'operator' => 'isfalse'],
		]))->toBe([]);
	});
});

describe('CollectionRefiner :: unknown fields and empty values', function (): void {
	it('returns nothing when filtering on a field that does not exist', function (): void {
		// THE most important test in this file. A misspelled property name must
		// return zero rows, never the whole collection — "filter by
		// members_only" typo'd as "membersonly" returning everything is how
		// gated content leaks to anonymous visitors.
		expect(collectionRefinerIdsWhere('membersonly', 'equal', 'yes'))->toBe([]);
	});

	it('returns nothing when a nested path does not exist', function (): void {
		expect(collectionRefinerIdsWhere('meta.subscription.tier', 'equal', 'gold'))->toBe([]);
	});

	it('DANGER: an empty filter value disables the rule and returns every row', function (): void {
		// Documented, deliberate behaviour (filterByRule returns the whole
		// collection when a two-argument operator gets ''), and a real leak
		// vector: any rule whose value comes from an unset request parameter or
		// an empty field silently becomes "match everything". Callers that gate
		// content must check for an empty value BEFORE building the rule.
		expect(collectionRefinerIdsWhere('membersonly', 'equal', ''))
			->toBe(['post-1', 'post-2', 'post-3']);
	});

	it('DANGER: a missing value key behaves the same as an empty value', function (): void {
		expect(collectionRefinerFilterIds([['property' => 'draft', 'operator' => 'equal']]))
			->toBe(['post-1', 'post-2', 'post-3']);
	});

	it('treats a boolean false value as a real filter, not a missing one', function (): void {
		// `{property: 'draft', operator: 'equal', value: false}` reads as
		// "published only", and it used to return the whole collection —
		// strval(false) is '', and an empty filter value means "no value
		// supplied, match everything", so the rule was dropped and DRAFTS WERE
		// RETURNED to anonymous visitors.
		//
		// Booleans are now mapped to '1'/'0' before that check. '0' is also the
		// correct comparison rather than merely a non-empty placeholder: equal()
		// is loose, and a stored `false` equals '0' while a stored `true` does
		// not.
		expect(collectionRefinerFilterIds([['property' => 'draft', 'operator' => 'equal', 'value' => false]]))
			->toBe(['post-1', 'post-3']);
	});

	it('treats a boolean true value as a real filter too', function (): void {
		expect(collectionRefinerFilterIds([['property' => 'draft', 'operator' => 'equal', 'value' => true]]))
			->toBe(['post-2']);
	});

	it('still treats a genuinely absent value as no filter', function (): void {
		// Deliberately unchanged: a rule whose value comes from a blank search
		// box should not filter anything out. Only the boolean case was wrong.
		expect(collectionRefinerFilterIds([['property' => 'draft', 'operator' => 'equal', 'value' => '']]))
			->toBe(['post-1', 'post-2', 'post-3']);
	});

	it('still excludes unknown fields for single-argument operators', function (): void {
		// One-argument operators (istrue/isempty/past/…) have no value to be
		// empty, so they never hit the "return everything" escape hatch — an
		// unknown field is correctly filtered out.
		expect(collectionRefinerIdsWhere('membersonly', 'istrue'))->toBe([]);
	});

	it('resolves a dotted property in the unrecognised-operator fallback', function (): void {
		// The fallback used $record[$property] — the raw key — so a dotted path
		// missed entirely and emitted an undefined-key warning on every row. It
		// now uses the value getPropertyValueForRecord() already resolved.
		$rows = [
			['id' => 'a', 'meta' => ['author' => 'joe']],
			['id' => 'b', 'meta' => ['author' => 'sam']],
		];

		expect(collectionRefinerIdsWhere('meta.author', 'nonsenseOperator', 'joe', $rows))->toBe(['a']);
	});

	it('does not dispatch to this class own methods as if they were operators', function (): void {
		// method_exists() matched anything on the class, so `operator:
		// 'filterUnique'` reached a method expecting an array, raising a
		// TypeError — a 500 for what should be an empty result or a plain
		// comparison. Only protected static helpers taking one or two arguments
		// are operators now.
		$rows = [['id' => 'a', 'title' => 'One'], ['id' => 'b', 'title' => 'Two']];

		foreach (['filterUnique', 'getPropertyValueForRecord', 'filterArrayByRule', 'filter'] as $notAnOperator) {
			expect(fn () => collectionRefinerIdsWhere('id', $notAnOperator, 'a', $rows))
				->not->toThrow(TypeError::class);
		}

		// Each degrades to the loose-comparison fallback.
		expect(collectionRefinerIdsWhere('id', 'filterUnique', 'a', $rows))->toBe(['a']);
	});
	it('falls back to a direct loose comparison for an unrecognised operator', function (): void {
		// A typo'd operator must not silently match everything; it degrades to
		// `$record[$property] == $value`.
		expect(collectionRefinerIdsWhere('category', 'nonsenseOperator', 'guide'))->toBe(['post-3']);
	});
});

describe('CollectionRefiner :: draft visibility', function (): void {
	it('excludes drafts with the isfalse operator', function (): void {
		// The correct, safe way to hide unpublished content in a Twig listing.
		expect(collectionRefinerIdsWhere('draft', 'isfalse'))->toBe(['post-1', 'post-3']);
	});

	it('selects only drafts with the istrue operator', function (): void {
		expect(collectionRefinerIdsWhere('draft', 'istrue'))->toBe(['post-2']);
	});

	it('inverts a single-argument operator with the not- prefix', function (): void {
		expect(collectionRefinerIdsWhere('draft', 'not-istrue'))->toBe(['post-1', 'post-3']);
	});

	it('excludes drafts when comparing against the integer 0', function (): void {
		// `value: 0` survives strval() as '0', so unlike `false` it really does
		// filter — loose comparison then matches draft === false.
		expect(collectionRefinerFilterIds([['property' => 'draft', 'operator' => 'equal', 'value' => 0]]))
			->toBe(['post-1', 'post-3']);
	});

	it('DANGER: comparing a boolean field against the string "false" matches drafts', function (): void {
		// PHP loose comparison casts the non-empty string 'false' to true, so
		// `draft == 'false'` is true for DRAFTS and false for published rows —
		// the exact inverse of what the author intended. Anyone writing
		// `{draft: 'false'}` in a template publishes their drafts.
		expect(collectionRefinerIdsWhere('draft', 'equal', 'false'))->toBe(['post-2']);
	});

	it('treats a missing draft flag as not-a-draft only for istrue, not isfalse', function (): void {
		// Older objects may have no `draft` key at all. getPropertyValueForRecord
		// returns null for them and null rows are dropped, so `isfalse` does NOT
		// return them — a listing filtered with `isfalse` silently loses legacy
		// published rows. Locked in so the behaviour is a decision, not a shock.
		$rows = [['id' => 'legacy', 'title' => 'No draft key']];
		expect(collectionRefinerIdsWhere('draft', 'isfalse', null, $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('draft', 'istrue', null, $rows))->toBe([]);
	});
});

describe('CollectionRefiner :: string operators', function (): void {
	it('matches with equal using loose comparison', function (): void {
		expect(collectionRefinerIdsWhere('category', 'equal', 'news'))->toBe(['post-1', 'post-2']);
	});

	it('matches with notEqual', function (): void {
		expect(collectionRefinerIdsWhere('category', 'notEqual', 'news'))->toBe(['post-3']);
	});

	it('matches with contains, case sensitively', function (): void {
		// Case sensitivity is the difference between a search box finding a post
		// and appearing broken, so it is pinned explicitly.
		expect(collectionRefinerIdsWhere('title', 'contains', 'Release'))->toBe(['post-1']);
		expect(collectionRefinerIdsWhere('title', 'contains', 'release'))->toBe(['post-2']);
	});

	it('matches with starts and ends', function (): void {
		expect(collectionRefinerIdsWhere('title', 'starts', 'Alpha'))->toBe(['post-1']);
		expect(collectionRefinerIdsWhere('title', 'ends', 'Notes'))->toBe(['post-3']);
	});

	it('keeps every row when a string operator is given an empty needle', function (): void {
		// filterByRule short-circuits before the operator ever runs, so a blank
		// "title ends with" search box shows the full listing instead of an
		// empty page. Pinned because the alternative — matching nothing — makes
		// a search form look broken on first render.
		$rows = collectionRefinerRows();
		expect(collectionRefinerFor($rows)->filterByRule($rows, 'title', '', 'ends'))->toHaveCount(3);
		expect(collectionRefinerFor($rows)->filterByRule($rows, 'title', '', 'endsCaseInsensitive'))->toHaveCount(3);
	});

	it('matches with the case-insensitive operator family', function (): void {
		expect(collectionRefinerIdsWhere('title', 'equalCaseInsensitive', 'BETA RELEASE'))->toBe(['post-2']);
		expect(collectionRefinerIdsWhere('title', 'notEqualCaseInsensitive', 'BETA RELEASE'))->toBe(['post-1', 'post-3']);
		expect(collectionRefinerIdsWhere('title', 'containsCaseInsensitive', 'RELEASE'))->toBe(['post-1', 'post-2']);
		expect(collectionRefinerIdsWhere('title', 'startsCaseInsensitive', 'alpha'))->toBe(['post-1']);
		expect(collectionRefinerIdsWhere('title', 'endsCaseInsensitive', 'RELEASE'))->toBe(['post-1', 'post-2']);
	});

	it('matches with like using a regular expression', function (): void {
		expect(collectionRefinerIdsWhere('title', 'like', '^(Alpha|Gamma)'))->toBe(['post-1', 'post-3']);
	});
});

describe('CollectionRefiner :: comparison operators', function (): void {
	// Numeric-looking values are compared numerically by PHP, which is what a
	// "price under 100" filter depends on. If these ever became string
	// comparisons, '9' would sort above '100' and price/date range filters
	// across the whole product would quietly return the wrong products.
	$numbers = [
		['id' => 'n9', 'v' => 9],
		['id' => 'n10', 'v' => 10],
		['id' => 'n100', 'v' => 100],
	];

	it('compares numerically with less / lesseq / greater / greatereq', function () use ($numbers): void {
		expect(collectionRefinerIdsWhere('v', 'less', '10', $numbers))->toBe(['n9']);
		expect(collectionRefinerIdsWhere('v', 'lesseq', '10', $numbers))->toBe(['n9', 'n10']);
		expect(collectionRefinerIdsWhere('v', 'greater', '10', $numbers))->toBe(['n100']);
		expect(collectionRefinerIdsWhere('v', 'greatereq', '10', $numbers))->toBe(['n10', 'n100']);
	});

	it('supports the short lt / le / gt / ge aliases identically', function () use ($numbers): void {
		// Documented shorthand in templates; if an alias drifted from its long
		// form, existing sites would change behaviour on upgrade with no error.
		expect(collectionRefinerIdsWhere('v', 'lt', '10', $numbers))
			->toBe(collectionRefinerIdsWhere('v', 'less', '10', $numbers));
		expect(collectionRefinerIdsWhere('v', 'le', '10', $numbers))
			->toBe(collectionRefinerIdsWhere('v', 'lesseq', '10', $numbers));
		expect(collectionRefinerIdsWhere('v', 'gt', '10', $numbers))
			->toBe(collectionRefinerIdsWhere('v', 'greater', '10', $numbers));
		expect(collectionRefinerIdsWhere('v', 'ge', '10', $numbers))
			->toBe(collectionRefinerIdsWhere('v', 'greatereq', '10', $numbers));
	});

	it('filters an inclusive numeric range with between', function (): void {
		expect(collectionRefinerIdsWhere('price', 'between', '25,75'))->toBe(['post-1', 'post-3']);
	});

	it('returns nothing rather than everything when a between range is malformed', function (): void {
		// Fail closed: a "10" range (no comma) must not become "any price".
		expect(collectionRefinerIdsWhere('price', 'between', '75'))->toBe([]);
	});

	it('matches emptiness with isempty and isnotempty', function (): void {
		expect(collectionRefinerIdsWhere('summary', 'isempty'))->toBe(['post-3']);
		expect(collectionRefinerIdsWhere('summary', 'isnotempty'))->toBe(['post-1', 'post-2']);
	});

	it('filters by text length with longerThan and shorterThan', function (): void {
		expect(collectionRefinerIdsWhere('summary', 'longerThan', '10'))->toBe(['post-2']);
		expect(collectionRefinerIdsWhere('summary', 'shorterThan', '10'))->toBe(['post-1', 'post-3']);
	});
});

describe('CollectionRefiner :: negation', function (): void {
	it('inverts a two-argument operator with the not- prefix', function (): void {
		expect(collectionRefinerIdsWhere('title', 'not-contains', 'Release'))->toBe(['post-2', 'post-3']);
	});

	it('inverts via a leading ! on the value', function (): void {
		// Shorthand used in Twig rule strings; equivalent to not-equal.
		expect(collectionRefinerIdsWhere('category', 'equal', '!news'))->toBe(['post-3']);
	});

	it('does not resurrect rows that lack the property when negating', function (): void {
		// Critical for gated content: `not-equal` on a field some rows do not
		// have must NOT include those rows. The null check runs before the
		// inversion, so rows missing the property stay excluded.
		$rows = [
			['id' => 'has', 'tier' => 'gold'],
			['id' => 'missing'],
		];
		expect(collectionRefinerIdsWhere('tier', 'not-equal', 'gold', $rows))->toBe([]);
	});
});

describe('CollectionRefiner :: array property values', function (): void {
	it('matches when any element of an array property satisfies the rule', function (): void {
		// Tag/category fields are stored as arrays; "any element matches" is the
		// behaviour every tag listing depends on.
		expect(collectionRefinerIdsWhere('tags', 'equal', 'web'))->toBe(['post-1', 'post-2']);
	});

	it('negates array membership as "has no such element"', function (): void {
		expect(collectionRefinerIdsWhere('tags', 'not-equal', 'web'))->toBe(['post-3']);
	});

	it('never matches an empty array property', function (): void {
		expect(collectionRefinerIdsWhere('tags', 'contains', 'php'))->toBe(['post-1']);
	});

	it('counts items with hasMin / hasMax / hasCount on a JSON-encoded string', function (): void {
		// These operators decode a JSON string field. Card/deck fields that were
		// serialized before storage take this path.
		$rows = [
			['id' => 'three', 'tags' => '["a","b","c"]'],
			['id' => 'one', 'tags' => '["a"]'],
			['id' => 'garbage', 'tags' => 'not-json'],
		];
		expect(collectionRefinerIdsWhere('tags', 'hasMin', '2', $rows))->toBe(['three']);
		expect(collectionRefinerIdsWhere('tags', 'hasCount', '1', $rows))->toBe(['one']);
		// Undecodable content counts as zero items, so it satisfies hasMax.
		expect(collectionRefinerIdsWhere('tags', 'hasMax', '1', $rows))->toBe(['one', 'garbage']);
	});

	it('counts a real array property, not just a JSON-encoded one', function (): void {
		// filterByRule dispatches per element when a property holds an array —
		// `tags contains 'php'` means "any element contains php". These three
		// operators ask about the array itself, so per-element dispatch handed
		// hasMin the string 'php', json_decode failed, and the count was always
		// 0: hasMin and hasCount never matched a real array, and hasMax matched
		// any non-empty one whatever its size.
		//
		// List fields are stored as real arrays, so this was the normal case,
		// not an edge one.
		$rows = [
			['id' => 'three', 'tags' => ['a', 'b', 'c']],
			['id' => 'one',   'tags' => ['a']],
		];

		expect(collectionRefinerIdsWhere('tags', 'hasMin', '2', $rows))->toBe(['three']);
		expect(collectionRefinerIdsWhere('tags', 'hasCount', '1', $rows))->toBe(['one']);
		expect(collectionRefinerIdsWhere('tags', 'hasMax', '1', $rows))->toBe(['one']);
	});

	it('still dispatches other operators per element on an array property', function (): void {
		// The fix must not turn every operator into a whole-array one: `contains`
		// still has to mean "any element contains this".
		$rows = [
			['id' => 'post-1', 'tags' => ['php', 'twig']],
			['id' => 'post-2', 'tags' => ['design']],
		];

		expect(collectionRefinerIdsWhere('tags', 'contains', 'php', $rows))->toBe(['post-1']);
		expect(collectionRefinerIdsWhere('tags', 'equal', 'design', $rows))->toBe(['post-2']);
	});
});

describe('CollectionRefiner :: nested properties', function (): void {
	it('resolves a dotted path into a nested value', function (): void {
		// Relationship/card fields are nested; `meta.author` style rules are
		// common in real templates.
		expect(collectionRefinerIdsWhere('meta.author', 'equal', 'joe'))->toBe(['post-1', 'post-3']);
	});

	it('reads the first element when a dotted path lands on an indexed array', function (): void {
		expect(CollectionRefiner::getPropertyValueForRecord(
			['meta' => ['editors' => ['kim', 'lee']]],
			'meta.editors'
		))->toBe('kim');
	});

	it('returns null for a missing top-level property', function (): void {
		expect(CollectionRefiner::getPropertyValueForRecord(['id' => 'x'], 'nope'))->toBeNull();
	});

	it('returns null for a missing segment of a dotted path', function (): void {
		expect(CollectionRefiner::getPropertyValueForRecord(['meta' => ['author' => 'joe']], 'meta.missing'))
			->toBeNull();
	});

	it('returns null when a dotted path runs through a scalar', function (): void {
		// A schema change that turns an object field into a string must not
		// fatal every template that reads through it.
		expect(CollectionRefiner::getPropertyValueForRecord(['meta' => 'flat'], 'meta.author'))->toBeNull();
	});

	it('returns null for an explicitly null value, so the row is filtered out', function (): void {
		// isset() is false for null, so a null field behaves exactly like a
		// missing one — rows with null values are excluded, never included.
		expect(CollectionRefiner::getPropertyValueForRecord(['author' => null], 'author'))->toBeNull();
	});

	it('resolves a value of false without treating it as missing', function (): void {
		// isset() is true for false, so a published (draft:false) row still
		// reaches the operator instead of being dropped as "no such property".
		expect(CollectionRefiner::getPropertyValueForRecord(['draft' => false], 'draft'))->toBeFalse();
	});
});

describe('CollectionRefiner :: date operators', function (): void {
	/** @return array<int,array<string,mixed>> */
	$relativeRows = static fn (): array => [
		['id' => 'today', 'date' => date('Y-m-d')],
		['id' => 'yesterday', 'date' => date('Y-m-d', strtotime('-1 day'))],
		['id' => 'tomorrow', 'date' => date('Y-m-d', strtotime('+1 day'))],
		['id' => 'plus5', 'date' => date('Y-m-d', strtotime('+5 days'))],
		['id' => 'minus5', 'date' => date('Y-m-d', strtotime('-5 days'))],
	];

	it('separates past from future dates', function (): void {
		// Scheduled publishing depends on this: a `future` gate that leaked a
		// past date would publish embargoed content early.
		expect(collectionRefinerIdsWhere('date', 'past'))->toBe(['post-1', 'post-3']);
		expect(collectionRefinerIdsWhere('date', 'future'))->toBe(['post-2']);
	});

	it('matches only the current day with today', function () use ($relativeRows): void {
		expect(collectionRefinerIdsWhere('date', 'today', null, $relativeRows()))->toBe(['today']);
	});

	it('includes today in pastToday and futureToday', function () use ($relativeRows): void {
		// Event listings use futureToday so an event happening today is still
		// shown; dropping today would make same-day events disappear.
		expect(collectionRefinerIdsWhere('date', 'futureToday', null, $relativeRows()))
			->toContain('today')
			->toContain('tomorrow')
			->not->toContain('yesterday');
		expect(collectionRefinerIdsWhere('date', 'pastToday', null, $relativeRows()))
			->toContain('yesterday')
			->not->toContain('tomorrow');
	});

	it('bounds a forward window with todayPlusDays', function () use ($relativeRows): void {
		// "Events in the next 3 days" — the upper bound must be inclusive of the
		// whole final day, and nothing in the past may sneak in.
		expect(collectionRefinerIdsWhere('date', 'todayPlusDays', '3', $relativeRows()))
			->toBe(['today', 'tomorrow']);
	});

	it('bounds a backward window with todayMinusDays', function () use ($relativeRows): void {
		expect(collectionRefinerIdsWhere('date', 'todayMinusDays', '3', $relativeRows()))
			->toBe(['today', 'yesterday']);
	});

	it('compares against an explicit date with before and after', function (): void {
		expect(collectionRefinerIdsWhere('date', 'before', '2010-01-01'))->toBe(['post-1']);
		expect(collectionRefinerIdsWhere('date', 'after', '2010-01-01'))->toBe(['post-2', 'post-3']);
	});

	it('scopes to the current calendar week, month and year', function () use ($relativeRows): void {
		// Assertions are anchored on "today" only, so they hold whatever day the
		// suite runs on; the 2000 row proves the window actually excludes.
		$rows   = $relativeRows();
		$rows[] = ['id' => 'ancient', 'date' => '2000-01-15'];

		expect(collectionRefinerIdsWhere('date', 'thisWeek', null, $rows))
			->toContain('today')->not->toContain('ancient');
		expect(collectionRefinerIdsWhere('date', 'thisMonth', null, $rows))
			->toContain('today')->not->toContain('ancient');
		expect(collectionRefinerIdsWhere('date', 'thisYear', null, $rows))
			->toContain('today')->not->toContain('ancient');
	});

	it('identifies weekdays and weekends', function (): void {
		$rows = [
			['id' => 'monday', 'date' => '2020-06-15'],
			['id' => 'saturday', 'date' => '2020-06-13'],
		];
		expect(collectionRefinerIdsWhere('date', 'isWeekday', null, $rows))->toBe(['monday']);
		expect(collectionRefinerIdsWhere('date', 'isWeekend', null, $rows))->toBe(['saturday']);
	});

	it('matches a specific day of week by name or by number', function (): void {
		// Recurring "every Monday" listings depend on the name and the ISO
		// number resolving to the same day.
		$rows = [
			['id' => 'monday', 'date' => '2020-06-15'],
			['id' => 'saturday', 'date' => '2020-06-13'],
		];
		expect(collectionRefinerIdsWhere('date', 'dayOfWeek', 'Monday', $rows))->toBe(['monday']);
		expect(collectionRefinerIdsWhere('date', 'dayOfWeek', '1', $rows))->toBe(['monday']);
		expect(collectionRefinerIdsWhere('date', 'dayOfWeek', '6', $rows))->toBe(['saturday']);
	});

	it('returns nothing for an unrecognised day name', function (): void {
		// Fail closed on a typo'd day rather than matching every row.
		expect(collectionRefinerIdsWhere('date', 'dayOfWeek', 'Funday'))->toBe([]);
	});

	it('rejects unparseable dates in the calendar operators', function (): void {
		$rows = [['id' => 'broken', 'date' => 'not-a-date']];
		expect(collectionRefinerIdsWhere('date', 'thisYear', null, $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'isWeekday', null, $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'isWeekend', null, $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'dayOfWeek', 'Monday', $rows))->toBe([]);
	});

	it('does not treat an unparseable date as being in the past', function (): void {
		// past()/future() compared strtotime() directly, and strtotime() returns
		// false for garbage — false < time() is true. A row with a corrupt date
		// therefore passed a `past` publish gate and went live. The guard now
		// matches thisYear()/isWeekday()/dayOfWeek(), which always had one.
		//
		// Failing closed is the safe direction: a broken date stops publishing
		// rather than publishing something unintended.
		$rows = [['id' => 'broken', 'date' => 'not-a-date']];

		expect(collectionRefinerIdsWhere('date', 'past', null, $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'future', null, $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'today', null, $rows))->toBe([]);
	});

	it('does not match an unparseable date against a boundary', function (): void {
		$rows = [['id' => 'broken', 'date' => 'not-a-date']];

		expect(collectionRefinerIdsWhere('date', 'before', '2030-01-01', $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'after', '2000-01-01', $rows))->toBe([]);
	});

	it('does not match when the boundary itself is unparseable', function (): void {
		// An unparseable boundary would otherwise make every row match or none,
		// depending on which side of the comparison failed.
		$rows = [['id' => 'ok', 'date' => '2020-06-01']];

		expect(collectionRefinerIdsWhere('date', 'before', 'not-a-date', $rows))->toBe([]);
		expect(collectionRefinerIdsWhere('date', 'after', 'not-a-date', $rows))->toBe([]);
	});

	it('treats an empty date field the same as a corrupt one', function (): void {
		// An unset date is the common real-world case — a row saved before the
		// field existed, or an import that left it blank.
		$rows = [['id' => 'blank', 'date' => '']];

		expect(collectionRefinerIdsWhere('date', 'past', null, $rows))->toBe([]);
	});

	it('still resolves genuine dates on both sides of now', function (): void {
		// The guard must not have broken the operators it protects.
		$rows = [
			['id' => 'old',   'date' => '2000-01-01'],
			['id' => 'ahead', 'date' => '2099-01-01'],
		];

		expect(collectionRefinerIdsWhere('date', 'past', null, $rows))->toBe(['old']);
		expect(collectionRefinerIdsWhere('date', 'future', null, $rows))->toBe(['ahead']);
		expect(collectionRefinerIdsWhere('date', 'before', '2050-01-01', $rows))->toBe(['old']);
		expect(collectionRefinerIdsWhere('date', 'after', '2050-01-01', $rows))->toBe(['ahead']);
	});
});

describe('CollectionRefiner :: array rules and de-duplication', function (): void {
	it('falls back to OR when the logic keyword is unrecognised', function (): void {
		// A typo'd `logic` must widen predictably, matching the documented
		// default, rather than dropping the rule entirely.
		expect(collectionRefinerFilterIds([[
			'property' => 'category',
			'operator' => 'equal',
			'value'    => ['news', 'guide'],
			'logic'    => 'xor',
		]]))->toBe(['post-1', 'post-2', 'post-3']);
	});

	it('does not emit an object twice when it matches several OR values', function (): void {
		// Without de-duplication a post tagged both 'php' and 'web' would render
		// twice in a tag listing.
		expect(collectionRefinerFilterIds([[
			'property' => 'tags',
			'operator' => 'equal',
			'value'    => ['php', 'web'],
			'logic'    => 'or',
		]]))->toBe(['post-1', 'post-2']);
	});

	it('de-duplicates by id with filterUnique', function (): void {
		// Two revisions of the same object (same id) must collapse to the first.
		$rows   = [['id' => 'a', 'n' => 1], ['id' => 'a', 'n' => 2], ['id' => 'b', 'n' => 3]];
		$unique = collectionRefinerFor()->filterUnique($rows);

		expect($unique)->toHaveCount(2);
		expect($unique[0]['n'])->toBe(1);
		expect(array_keys($unique))->toBe([0, 1]);
	});

	it('de-duplicates identical id-less rows by value', function (): void {
		// Joined/derived rows have no id; falling back to serialize() keeps a
		// grouped listing from repeating the same entry.
		$rows = [['n' => 1], ['n' => 1], ['n' => 2]];
		expect(collectionRefinerFor()->filterUnique($rows))->toHaveCount(2);
	});

	it('leaves an already unique collection untouched', function (): void {
		expect(collectionRefinerFor()->filterUnique(collectionRefinerRows()))
			->toBe(collectionRefinerRows());
	});
});

describe('CollectionRefiner :: result shape and pagination', function (): void {
	it('preserves the original array keys, so results are not a packed list', function (): void {
		// filter() is array_filter based, so keys are sparse. Any caller slicing
		// for pagination MUST array_values() first — array_slice with
		// preserve_keys defaults would otherwise page the wrong rows. This is a
		// contract worth pinning because it silently breaks "page 2".
		$result = collectionRefinerFor()->filter([
			['property' => 'category', 'operator' => 'equal', 'value' => 'guide'],
		]);

		expect(array_keys($result))->toBe([2]);
	});

	it('supports the real-world filter, sort then paginate pipeline', function (): void {
		// The full path a listing takes: narrow, order, then take a page.
		$filtered = array_values(collectionRefinerFor()->filter([
			['property' => 'draft', 'operator' => 'isfalse'],
		]));
		usort($filtered, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);

		expect(array_column($filtered, 'id'))->toBe(['post-1', 'post-3']);
		expect(array_column(array_slice($filtered, 0, 1), 'id'))->toBe(['post-1']);
	});

	it('returns a short final page rather than padding or wrapping', function (): void {
		// Off-by-one at the last page: 2 published rows, page size 1, page 2 has
		// exactly one row and page 3 is empty — not a wrap back to page 1.
		$filtered = array_values(collectionRefinerFor()->filter([
			['property' => 'draft', 'operator' => 'isfalse'],
		]));

		expect(array_column(array_slice($filtered, 1, 1), 'id'))->toBe(['post-3']);
		expect(array_slice($filtered, 2, 1))->toBe([]);
	});

	it('returns everything it has when the page size exceeds the result set', function (): void {
		$filtered = array_values(collectionRefinerFor()->filter([
			['property' => 'category', 'operator' => 'equal', 'value' => 'guide'],
		]));

		expect(array_slice($filtered, 0, 50))->toHaveCount(1);
	});
});
