<?php

namespace Tests\Unit\Domain\Query\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Query\Service\ObjectFilter;

final class ObjectFilterTest extends TestCase
{
	private ObjectFilter $filter;

	protected function setUp(): void
	{
		$this->filter = new ObjectFilter();
	}

	// --- isFilterableType — canonical answer to "does this form-field type ─────
	//     make sense to include/exclude on?" Sourced from SchemaData's central
	//     FILTERABLE_FIELD_TYPES catalog. Consumers (MCP catalog builder, future
	//     REST query validators, admin search UI) all funnel through here so the
	//     answer stays consistent.

	public function testIsFilterableTypeReturnsTrueForCanonicalScalarTypes(): void
	{
		$this->assertTrue(ObjectFilter::isFilterableType('text'));
		$this->assertTrue(ObjectFilter::isFilterableType('select'));
		$this->assertTrue(ObjectFilter::isFilterableType('toggle'));
		$this->assertTrue(ObjectFilter::isFilterableType('number'));
		$this->assertTrue(ObjectFilter::isFilterableType('date'));
	}

	public function testIsFilterableTypeReturnsTrueForIdField(): void
	{
		// The `id` form-field type is every collection's slug-like primary key —
		// exact-match filters on it are a core query pattern. Verify it explicitly
		// to lock in the loose-thread fix from Chunk B planning.
		$this->assertTrue(ObjectFilter::isFilterableType('id'));
	}

	public function testIsFilterableTypeReturnsFalseForComplexBlobs(): void
	{
		// Container/blob fields aren't usefully filterable by string match —
		// you'd be matching against serialized JSON. AI agents should not be
		// pointed at them in the filter catalog.
		$this->assertFalse(ObjectFilter::isFilterableType('card'));
		$this->assertFalse(ObjectFilter::isFilterableType('deck'));
		$this->assertFalse(ObjectFilter::isFilterableType('image'));
		$this->assertFalse(ObjectFilter::isFilterableType('gallery'));
	}

	public function testIsFilterableTypeReturnsFalseForUnknownType(): void
	{
		// Defensive default: an unrecognised field type is NOT filterable.
		// Adding new types should be a deliberate opt-in via SchemaData's
		// constant, not "anything string-shaped works".
		$this->assertFalse(ObjectFilter::isFilterableType('made-up-type'));
	}

	// --- extractFilterOptions ---

	public function testExtractFilterOptionsIncludeOnly(): void
	{
		$result = $this->filter->extractFilterOptions(['include' => 'published:true', 'limit' => '10']);

		$this->assertSame(['include' => 'published:true'], $result);
	}

	public function testExtractFilterOptionsExcludeOnly(): void
	{
		$result = $this->filter->extractFilterOptions(['exclude' => 'draft:true', 'sort' => 'date']);

		$this->assertSame(['exclude' => 'draft:true'], $result);
	}

	public function testExtractFilterOptionsBoth(): void
	{
		$result = $this->filter->extractFilterOptions([
			'include' => 'published:true',
			'exclude' => 'archived:true',
		]);

		$this->assertSame([
			'include' => 'published:true',
			'exclude' => 'archived:true',
		], $result);
	}

	public function testExtractFilterOptionsReturnsEmptyWhenNone(): void
	{
		$result = $this->filter->extractFilterOptions(['limit' => '10', 'sort' => 'date']);

		$this->assertSame([], $result);
	}

	// --- parseFilterString ---

	public function testParseFilterStringFieldValue(): void
	{
		$result = $this->filter->parseFilterString('category:news');

		$this->assertCount(1, $result);
		$this->assertSame('category', $result[0]['field']);
		$this->assertSame('news', $result[0]['value']);
	}

	public function testParseFilterStringMultipleFields(): void
	{
		$result = $this->filter->parseFilterString('category:news,published:true');

		$this->assertCount(2, $result);
		$this->assertSame('category', $result[0]['field']);
		$this->assertSame('news', $result[0]['value']);
		$this->assertSame('published', $result[1]['field']);
		$this->assertTrue($result[1]['value']);
	}

	public function testParseFilterStringDefaultsToTrue(): void
	{
		$result = $this->filter->parseFilterString('featured');

		$this->assertCount(1, $result);
		$this->assertSame('featured', $result[0]['field']);
		$this->assertTrue($result[0]['value']);
	}

	public function testParseFilterStringFalseValue(): void
	{
		$result = $this->filter->parseFilterString('archived:false');

		$this->assertCount(1, $result);
		$this->assertSame('archived', $result[0]['field']);
		$this->assertFalse($result[0]['value']);
	}

	// --- filterObjects ---

	public function testFilterObjectsInclude(): void
	{
		$objects = [
			['id' => '1', 'published' => true],
			['id' => '2', 'published' => false],
			['id' => '3', 'published' => true],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'published:true']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testFilterObjectsExclude(): void
	{
		$objects = [
			['id' => '1', 'draft' => true],
			['id' => '2', 'draft' => false],
			['id' => '3', 'draft' => true],
		];

		$result = $this->filter->filterObjects($objects, ['exclude' => 'draft:true']);

		$this->assertCount(1, $result);
		$this->assertSame('2', $result[0]['id']);
	}

	public function testFilterObjectsCombinedIncludeExclude(): void
	{
		$objects = [
			['id' => '1', 'published' => true, 'archived' => false],
			['id' => '2', 'published' => true, 'archived' => true],
			['id' => '3', 'published' => false, 'archived' => false],
		];

		$result = $this->filter->filterObjects($objects, [
			'include' => 'published:true',
			'exclude' => 'archived:true',
		]);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testFilterObjectsNoFiltersReturnsAll(): void
	{
		$objects = [
			['id' => '1'],
			['id' => '2'],
		];

		$result = $this->filter->filterObjects($objects, []);

		$this->assertCount(2, $result);
	}

	// --- matchesFilter ---

	public function testMatchesFilterBooleanField(): void
	{
		$object = ['published' => true];

		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'published:true']));
		$this->assertFalse($this->filter->matchesFilter($object, ['include' => 'published:false']));
	}

	public function testMatchesFilterStringFieldCaseInsensitive(): void
	{
		$object = ['category' => 'News'];

		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'category:news']));
		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'category:NEWS']));
	}

	public function testMatchesFilterArrayFieldCaseInsensitive(): void
	{
		$object = ['tags' => ['News', 'Tech', 'Science']];

		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'tags:news']));
		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'tags:TECH']));
		$this->assertFalse($this->filter->matchesFilter($object, ['include' => 'tags:sports']));
	}

	public function testMatchesFilterMissingFieldExcludedFromInclude(): void
	{
		$object = ['id' => '1'];

		$this->assertFalse($this->filter->matchesFilter($object, ['include' => 'published:true']));
	}

	public function testMatchesFilterExcludeTakesPrecedence(): void
	{
		$object = ['published' => true, 'archived' => true];

		// Object matches include but also matches exclude — should be excluded
		$this->assertFalse($this->filter->matchesFilter($object, [
			'include' => 'published:true',
			'exclude' => 'archived:true',
		]));
	}

	public function testMatchesFilterEmptyOptionsReturnsTrue(): void
	{
		$object = ['id' => '1'];

		$this->assertTrue($this->filter->matchesFilter($object, []));
	}

	public function testFilterObjectsReindexesKeys(): void
	{
		$objects = [
			['id' => '1', 'published' => false],
			['id' => '2', 'published' => true],
			['id' => '3', 'published' => false],
			['id' => '4', 'published' => true],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'published:true']);

		$this->assertCount(2, $result);
		$this->assertArrayHasKey(0, $result);
		$this->assertArrayHasKey(1, $result);
		$this->assertSame('2', $result[0]['id']);
		$this->assertSame('4', $result[1]['id']);
	}

	public function testIncludeWithMultipleConditionsRequiresAll(): void
	{
		$objects = [
			['id' => '1', 'published' => true, 'category' => 'news'],
			['id' => '2', 'published' => true, 'category' => 'blog'],
			['id' => '3', 'published' => false, 'category' => 'news'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'published:true,category:news']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testExcludeWithMultipleConditionsExcludesAny(): void
	{
		$objects = [
			['id' => '1', 'draft' => true, 'archived' => false],
			['id' => '2', 'draft' => false, 'archived' => true],
			['id' => '3', 'draft' => false, 'archived' => false],
		];

		$result = $this->filter->filterObjects($objects, ['exclude' => 'draft:true,archived:true']);

		$this->assertCount(1, $result);
		$this->assertSame('3', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// parseFilterString — operator-qualified (3-part) syntax
	// ─────────────────────────────────────────────────────────────────────────────

	public function testParseFilterStringThreePartOperator(): void
	{
		$result = $this->filter->parseFilterString('price:lte:500000');

		$this->assertCount(1, $result);
		$this->assertSame('price', $result[0]['field']);
		$this->assertSame('lte', $result[0]['op']);
		$this->assertSame('500000', $result[0]['value']);
	}

	public function testParseFilterStringThreePartContains(): void
	{
		$result = $this->filter->parseFilterString('city:contains:Austin');

		$this->assertCount(1, $result);
		$this->assertSame('city', $result[0]['field']);
		$this->assertSame('contains', $result[0]['op']);
		$this->assertSame('Austin', $result[0]['value']);
	}

	public function testParseFilterStringTwoPartImpliesEq(): void
	{
		$result = $this->filter->parseFilterString('status:active');

		$this->assertCount(1, $result);
		$this->assertSame('status', $result[0]['field']);
		$this->assertSame('eq', $result[0]['op']);
		$this->assertSame('active', $result[0]['value']);
	}

	public function testParseFilterStringThreePartUnknownMiddleTokenTreatedAsValue(): void
	{
		// Middle token "someval" is not a known operator; treated as two-part with colon value.
		$result = $this->filter->parseFilterString('field:someval:extra');

		$this->assertCount(1, $result);
		$this->assertSame('field', $result[0]['field']);
		$this->assertSame('eq', $result[0]['op']);
		$this->assertSame('someval:extra', $result[0]['value']);
	}

	public function testParseFilterStringBooleanCoercedForEqOnly(): void
	{
		// eq: "true" string → boolean true
		$eq = $this->filter->parseFilterString('field:eq:true');
		$this->assertTrue($eq[0]['value']);

		// lte: "true" stays as string
		$lte = $this->filter->parseFilterString('count:lte:true');
		$this->assertSame('true', $lte[0]['value']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// ne — not-equal operator
	// ─────────────────────────────────────────────────────────────────────────────

	public function testNeOperatorIncludesNonMatchingObjects(): void
	{
		$objects = [
			['id' => '1', 'status' => 'active'],
			['id' => '2', 'status' => 'archived'],
			['id' => '3', 'status' => 'active'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'status:ne:archived']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testNeOperatorExcludesMatchingObjects(): void
	{
		$objects = [
			['id' => '1', 'status' => 'active'],
			['id' => '2', 'status' => 'archived'],
			['id' => '3', 'status' => 'pending'],
		];

		// Exclude anything whose status is NOT active (ne:active => anything ≠ active triggers exclusion)
		$result = $this->filter->filterObjects($objects, ['exclude' => 'status:ne:active']);

		// Objects 2 and 3 have status ≠ "active" so they are excluded.
		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// lt / lte / gt / gte — numeric comparison operators
	// ─────────────────────────────────────────────────────────────────────────────

	public function testLtOperatorFiltersNumericValues(): void
	{
		$objects = [
			['id' => '1', 'price' => 100],
			['id' => '2', 'price' => 200],
			['id' => '3', 'price' => 300],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'price:lt:200']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testLteOperatorFiltersNumericValues(): void
	{
		$objects = [
			['id' => '1', 'price' => 100],
			['id' => '2', 'price' => 200],
			['id' => '3', 'price' => 300],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'price:lte:200']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('2', $result[1]['id']);
	}

	public function testGtOperatorFiltersNumericValues(): void
	{
		$objects = [
			['id' => '1', 'price' => 100],
			['id' => '2', 'price' => 200],
			['id' => '3', 'price' => 300],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'price:gt:200']);

		$this->assertCount(1, $result);
		$this->assertSame('3', $result[0]['id']);
	}

	public function testGteOperatorFiltersNumericValues(): void
	{
		$objects = [
			['id' => '1', 'price' => 100],
			['id' => '2', 'price' => 200],
			['id' => '3', 'price' => 300],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'price:gte:200']);

		$this->assertCount(2, $result);
		$this->assertSame('2', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testNumericOperatorHandlesStringStoredNumbers(): void
	{
		// T3 sometimes stores numbers as strings in JSON — both sides must coerce.
		$objects = [
			['id' => '1', 'score' => '85'],
			['id' => '2', 'score' => '92'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'score:gte:90']);

		$this->assertCount(1, $result);
		$this->assertSame('2', $result[0]['id']);
	}

	public function testNumericOperatorReturnsFalseForNonNumericField(): void
	{
		// When the field value is not numeric, the object is excluded.
		$objects = [
			['id' => '1', 'score' => 'n/a'],
			['id' => '2', 'score' => 100],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'score:gte:50']);

		$this->assertCount(1, $result);
		$this->assertSame('2', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// contains — case-insensitive substring match
	// ─────────────────────────────────────────────────────────────────────────────

	public function testContainsOperatorCaseInsensitiveSubstring(): void
	{
		$objects = [
			['id' => '1', 'city' => 'Austin'],
			['id' => '2', 'city' => 'Dallas'],
			['id' => '3', 'city' => 'San Antonio'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'city:contains:aus']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testContainsOperatorMatchesUpperCase(): void
	{
		$objects = [
			['id' => '1', 'city' => 'AUSTIN'],
			['id' => '2', 'city' => 'Dallas'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'city:contains:austin']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testContainsOperatorExcludesNonMatch(): void
	{
		$objects = [
			['id' => '1', 'city' => 'Austin'],
			['id' => '2', 'city' => 'Dallas'],
		];

		$result = $this->filter->filterObjects($objects, ['exclude' => 'city:contains:dall']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// starts — case-insensitive prefix match
	// ─────────────────────────────────────────────────────────────────────────────

	public function testStartsOperatorCaseInsensitivePrefix(): void
	{
		$objects = [
			['id' => '1', 'name' => 'Apple Watch'],
			['id' => '2', 'name' => 'Samsung Galaxy'],
			['id' => '3', 'name' => 'Apple MacBook'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'name:starts:apple']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testStartsOperatorDoesNotMatchMidString(): void
	{
		$objects = [
			['id' => '1', 'name' => 'Apple Watch'],
			['id' => '2', 'name' => 'My Apple'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'name:starts:apple']);

		// "My Apple" starts with "My", not "apple" — only Apple Watch matches.
		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// ends — case-insensitive suffix match
	// ─────────────────────────────────────────────────────────────────────────────

	public function testEndsOperatorCaseInsensitiveSuffix(): void
	{
		$objects = [
			['id' => '1', 'email' => 'admin@example.com'],
			['id' => '2', 'email' => 'user@example.org'],
			['id' => '3', 'email' => 'other@example.com'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'email:ends:.com']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testEndsOperatorDoesNotMatchPrefix(): void
	{
		$objects = [
			['id' => '1', 'tag' => 'com.example'],
			['id' => '2', 'tag' => 'example.com'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'tag:ends:com']);

		// "com.example" does not end with "com".
		$this->assertCount(1, $result);
		$this->assertSame('2', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// in / notin — list membership operators
	// ─────────────────────────────────────────────────────────────────────────────

	public function testInOperatorMatchesAnyListMember(): void
	{
		$objects = [
			['id' => '1', 'category' => 'tech'],
			['id' => '2', 'category' => 'travel'],
			['id' => '3', 'category' => 'food'],
			['id' => '4', 'category' => 'sports'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'category:in:tech|travel|food']);

		$this->assertCount(3, $result);
		$ids = array_column($result, 'id');
		$this->assertContains('1', $ids);
		$this->assertContains('2', $ids);
		$this->assertContains('3', $ids);
		$this->assertNotContains('4', $ids);
	}

	public function testInOperatorIsCaseSensitive(): void
	{
		$objects = [
			['id' => '1', 'category' => 'Tech'],
			['id' => '2', 'category' => 'tech'],
		];

		// in list uses lowercase "tech" — only exact case matches.
		$result = $this->filter->filterObjects($objects, ['include' => 'category:in:tech']);

		$this->assertCount(1, $result);
		$this->assertSame('2', $result[0]['id']);
	}

	public function testNotinOperatorExcludesListMembers(): void
	{
		$objects = [
			['id' => '1', 'status' => 'active'],
			['id' => '2', 'status' => 'archived'],
			['id' => '3', 'status' => 'draft'],
			['id' => '4', 'status' => 'active'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'status:notin:archived|draft']);

		$this->assertCount(2, $result);
		$ids = array_column($result, 'id');
		$this->assertContains('1', $ids);
		$this->assertContains('4', $ids);
	}

	public function testNotinOperatorOnExclude(): void
	{
		$objects = [
			['id' => '1', 'role' => 'admin'],
			['id' => '2', 'role' => 'editor'],
			['id' => '3', 'role' => 'viewer'],
		];

		// Exclude objects whose role is NOT in the trusted list.
		$result = $this->filter->filterObjects($objects, ['exclude' => 'role:notin:admin|editor']);

		// "viewer" is not in the list so notin is true → excluded.
		$this->assertCount(2, $result);
		$ids = array_column($result, 'id');
		$this->assertContains('1', $ids);
		$this->assertContains('2', $ids);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Cross-operator — multiple operators in a single include string
	// ─────────────────────────────────────────────────────────────────────────────

	public function testCrossOperatorContainsAndLte(): void
	{
		// Simulates the find_listings fixture: city contains "Austin" AND price ≤ 500000.
		$objects = [
			['id' => 'austin-1',     'city' => 'Austin', 'price' => 450000, 'status' => 'active', 'draft' => false],
			['id' => 'austin-2',     'city' => 'Austin', 'price' => 650000, 'status' => 'active', 'draft' => false],
			['id' => 'dallas-1',     'city' => 'Dallas', 'price' => 380000, 'status' => 'active', 'draft' => false],
			['id' => 'austin-draft', 'city' => 'Austin', 'price' => 500000, 'status' => 'active', 'draft' => true],
		];

		$result = $this->filter->filterObjects($objects, [
			'include' => 'status:active,city:contains:Austin,price:lte:500000',
			'exclude' => 'draft:true',
		]);

		$this->assertCount(1, $result);
		$this->assertSame('austin-1', $result[0]['id']);
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// matchesString legacy wildcard syntax — regression guard
	//
	// ObjectFilter::matchesString() supports legacy wildcard patterns used by
	// existing T3 callers building filter strings with `*` prefix/suffix.
	// These are processed in the `eq` inline path BEFORE the operator-coverage
	// refactor routes non-eq operators through applyOperator(). A regression
	// in matchesString() would silently break callers that rely on this syntax.
	// ─────────────────────────────────────────────────────────────────────────────

	public function testWildcardPrefixMatchesStartsWith(): void
	{
		// "value*" — field must start with "value" (case-insensitive)
		$objects = [
			['id' => '1', 'slug' => 'article-intro'],
			['id' => '2', 'slug' => 'news-brief'],
			['id' => '3', 'slug' => 'article-review'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'slug:article*']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testWildcardSuffixMatchesEndsWith(): void
	{
		// "*value" — field must end with "value" (case-insensitive)
		$objects = [
			['id' => '1', 'slug' => 'intro-article'],
			['id' => '2', 'slug' => 'news-brief'],
			['id' => '3', 'slug' => 'review-article'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'slug:*article']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testWildcardBothSidesMatchesContains(): void
	{
		// "*value*" — field must contain "value" (case-insensitive)
		$objects = [
			['id' => '1', 'title' => 'Getting started with PHP'],
			['id' => '2', 'title' => 'TypeScript deep dive'],
			['id' => '3', 'title' => 'PHP best practices guide'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'title:*php*']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testOrderedOperatorsCompareDateStrings(): void
	{
		// lt/lte/gt/gte required both sides to be numeric, so date fields
		// never matched — `reviewed:lte:2026-07-26` silently returned nothing
		// from REST, sitemap filters, and MCP saved-query tools alike. Dates
		// now compare via strtotime when either side is non-numeric.
		$objects = [
			['id' => 'old', 'reviewed' => '2026-07-25'],
			['id' => 'mid', 'reviewed' => '2026-07-26'],
			['id' => 'new', 'reviewed' => '2026-07-29'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'reviewed:lte:2026-07-26']);
		$this->assertSame(['old', 'mid'], array_column($result, 'id'));

		$result = $this->filter->filterObjects($objects, ['include' => 'reviewed:gt:2026-07-26']);
		$this->assertSame(['new'], array_column($result, 'id'));
	}

	public function testOrderedOperatorsCompareMixedPrecisionDates(): void
	{
		// A bare date cutoff against full ISO-8601 stored values — the common
		// real shape (stored timestamps, human-entered cutoff).
		$objects = [
			['id' => 'early', 'updated' => '2026-07-25T08:00:00+00:00'],
			['id' => 'late',  'updated' => '2026-07-29T22:15:00+00:00'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'updated:gte:2026-07-27']);
		$this->assertSame(['late'], array_column($result, 'id'));
	}

	public function testOrderedOperatorsStillRejectUnorderableValues(): void
	{
		// Neither numeric nor date on both sides → no defined order → no match.
		$objects = [
			['id' => '1', 'title' => 'Alpha'],
			['id' => '2', 'title' => 'Beta'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'title:lte:Beta']);
		$this->assertSame([], array_column($result, 'id'));

		// And numeric comparison is unchanged.
		$objects = [['id' => 'cheap', 'price' => '10'], ['id' => 'dear', 'price' => '99']];
		$result  = $this->filter->filterObjects($objects, ['include' => 'price:lte:50']);
		$this->assertSame(['cheap'], array_column($result, 'id'));
	}

	public function testWildcardNotShadowedByOperatorPath(): void
	{
		// Confirm that a two-part filter value containing a wildcard (no explicit
		// operator) still routes through matchesString() and is NOT swallowed by
		// the applyOperator() path (which only fires for three-part filters).
		$objects = [
			['id' => '1', 'category' => 'tech-news'],
			['id' => '2', 'category' => 'sports'],
		];

		// "category:tech*" is a two-part filter → op=eq → matchesString()
		$result = $this->filter->filterObjects($objects, ['include' => 'category:tech*']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}
}
