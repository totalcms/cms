<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\PodcastFeedMapper;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * The two podcast schemas are the contract between the admin form and
 * cms.feed.podcast(): the mapper reads index rows, so every field it needs
 * must be indexed, and the category field must feed from Apple's taxonomy.
 */
function podcastSchema(string $id): array
{
	$json = file_get_contents(reservedSchemaPath() . '/' . $id . '.json');
	expect($json)->not->toBeFalse();

	$data = json_decode((string)$json, true);
	expect($data)->toBeArray();

	return $data;
}

describe('Podcast schemas', function (): void {
	test('both files parse and carry their own id', function (): void {
		expect(podcastSchema('podcast')['id'])->toBe('podcast');
		expect(podcastSchema('podcast-episode')['id'])->toBe('podcast-episode');
	});

	test('both are reserved and neither is provisioned by default', function (): void {
		foreach (['podcast', 'podcast-episode'] as $id) {
			expect(SchemaData::RESERVED_SCHEMAS)->toContain($id);
			expect(SchemaData::DEFAULT_COLLECTIONS)->not->toContain($id);
		}
	});

	test('the show category field selects from Apple\'s taxonomy', function (): void {
		$categories = podcastSchema('podcast')['properties']['categories'];

		expect($categories['field'])->toBe('list');
		expect($categories['$ref'])->toEndWith('/properties/list.json');
		expect($categories['settings']['propertyOptions'])->toBe('podcastCategories');
	});

	test('the show schema requires what Apple requires', function (): void {
		expect(podcastSchema('podcast')['required'])->toContain('author', 'ownerEmail', 'cover', 'categories', 'description', 'feedUrl');
	});

	test('the episode index carries every field the feed mapper reads', function (): void {
		$index = podcastSchema('podcast-episode')['index'];

		foreach (PodcastFeedMapper::EPISODE_FIELDS as $field) {
			expect($index)->toContain($field);
		}
	});

	test('the episode audio field accepts only audio uploads', function (): void {
		$types = podcastSchema('podcast-episode')['properties']['audio']['settings']['rules']['filetype'];

		expect($types)->toContain('audio/mpeg');
		foreach ($types as $type) {
			expect($type)->toStartWith('audio/');
		}
	});
});
