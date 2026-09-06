<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\PodcastCategories;

/**
 * Apple validates the category pair, not the words, and rejects the feed on a
 * miss. These tests pin the two things the writer relies on: canonical
 * resolution in any case/spacing, and a useful suggestion when it fails.
 */
describe('PodcastCategories', function (): void {
	test('accepts a top-level category, case-insensitively', function (): void {
		expect(PodcastCategories::canonical('technology'))->toBe('Technology');
		expect(PodcastCategories::canonical('True Crime'))->toBe('True Crime');
	});

	test('accepts Parent > Child with loose spacing', function (): void {
		expect(PodcastCategories::canonical('business>entrepreneurship'))->toBe('Business > Entrepreneurship');
		expect(PodcastCategories::canonical('Business  >  Entrepreneurship'))->toBe('Business > Entrepreneurship');
	});

	test('rejects an unknown category or a child under the wrong parent', function (): void {
		expect(PodcastCategories::canonical('Podcasting'))->toBeNull();
		expect(PodcastCategories::canonical('Technology > Entrepreneurship'))->toBeNull();
	});

	test('suggests nearby categories for a miss', function (): void {
		$suggestions = PodcastCategories::suggest('Tecnology');

		expect($suggestions)->toContain('Technology');
		expect(count($suggestions))->toBeLessThanOrEqual(3);
	});

	test('suggests children when a parent is given that has children', function (): void {
		expect(PodcastCategories::suggest('Sports > Footbal'))->toContain('Sports > American Football');
	});

	test('a contained word outranks edit distance, so the old short names still find their category', function (): void {
		expect(PodcastCategories::suggest('Soccer'))->toContain('Sports > Football (Soccer)');
		expect(PodcastCategories::suggest('Football'))->toContain('Sports > American Football');
	});

	test('matches the entry count on Apple\'s page as verified on 2026-09-06', function (): void {
		$entries = count(PodcastCategories::TAXONOMY) + array_sum(array_map(count(...), PodcastCategories::TAXONOMY));

		expect($entries)->toBe(110);
	});

	test('the taxonomy is populated', function (): void {
		expect(count(PodcastCategories::TAXONOMY))->toBeGreaterThan(15);
		expect(PodcastCategories::TAXONOMY['Technology'])->toBe([]);
		expect(PodcastCategories::TAXONOMY['Business'])->toContain('Entrepreneurship');
	});
});
