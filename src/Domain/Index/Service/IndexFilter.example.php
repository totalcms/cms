<?php

/**
 * IndexFilter Service - Usage Examples
 *
 * This service provides reusable filtering functionality for index objects
 * using include/exclude criteria.
 */

// Example 1: Basic filtering with filterObjects()
$filter = new \TotalCMS\Domain\Index\Service\IndexFilter();

$objects = [
	['id' => '1', 'published' => true, 'featured' => true, 'draft' => false],
	['id' => '2', 'published' => true, 'featured' => false, 'draft' => true],
	['id' => '3', 'published' => false, 'featured' => true, 'draft' => false],
];

// Filter to only published, non-draft objects
$filtered = $filter->filterObjects($objects, [
	'include' => 'published:true',
	'exclude' => 'draft:true',
]);
// Result: ['1'] - object 2 is draft, object 3 is not published

// Example 2: Multiple include criteria (ALL must match)
$filtered = $filter->filterObjects($objects, [
	'include' => 'published:true,featured:true',
]);
// Result: ['1'] - only object 1 matches both criteria

// Example 3: Multiple exclude criteria (ANY match excludes)
$filtered = $filter->filterObjects($objects, [
	'exclude' => 'draft:true,featured:false',
]);
// Result: ['1', '3'] - objects 2 is excluded (matches draft:true)

// Example 4: Using matchesFilter() for single object
$object = ['id' => '1', 'published' => true, 'status' => 'active'];

$matches = $filter->matchesFilter($object, [
	'include' => 'published:true,status:active',
]);
// Result: true

// Example 5: Default value (field without value defaults to true)
$matches = $filter->matchesFilter(['published' => true], [
	'include' => 'published', // Same as 'published:true'
]);
// Result: true

// Example 6: Boolean false values
$matches = $filter->matchesFilter(['draft' => false], [
	'include' => 'draft:false',
]);
// Result: true

// Example 7: String values
$matches = $filter->matchesFilter(['status' => 'active', 'category' => 'news'], [
	'include' => 'status:active,category:news',
]);
// Result: true

// Example 8: Parsing filter strings
$parsed = $filter->parseFilterString('published:true,featured:true,status:active');
// Result:
// [
//     ['field' => 'published', 'value' => true],
//     ['field' => 'featured', 'value' => true],
//     ['field' => 'status', 'value' => 'active'],
// ]

// Example 9: Extract filter options from mixed options array
$options = [
	'include' => 'published:true',
	'exclude' => 'draft:true',
	'limit'   => 10,        // Not a filter option
	'offset'  => 0,         // Not a filter option
];

$filterOptions = $filter->extractFilterOptions($options);
// Result: ['include' => 'published:true', 'exclude' => 'draft:true']
// Note: $options still contains 'limit' and 'offset'

// Example 10: RSS Feed filtering (real-world usage)
$indexReader = new \TotalCMS\Domain\Index\Service\IndexReader($repository);
$index = $indexReader->fetchIndex('blog');

$filtered = $filter->filterObjects($index->objects, [
	'include' => 'published:true',
	'exclude' => 'draft:true,archived:true',
]);
// Only published, non-draft, non-archived posts in RSS feed

// Example 11: Collection Grid filtering
$filtered = $filter->filterObjects($collection->objects, [
	'include' => 'category:products,inStock:true',
	'exclude' => 'discontinued:true',
]);
// Only in-stock products that aren't discontinued

// Example 12: Exclude takes precedence over include
$matches = $filter->matchesFilter(['published' => true, 'draft' => true], [
	'include' => 'published:true',
	'exclude' => 'draft:true',
]);
// Result: false (excluded even though it matches include)
