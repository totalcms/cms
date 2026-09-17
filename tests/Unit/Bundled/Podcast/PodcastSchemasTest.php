<?php

declare(strict_types=1);

use TotalCMS\Bundled\Podcast\PodcastFeedMapper;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/podcast/PodcastFeedMapper.php';
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * The two podcast schemas ship with the bundled extension and are the
 * contract between the admin form and the feed: the mapper reads index rows,
 * so every field it needs must be indexed, the show must name its episodes
 * collection, and the category field must feed from Apple's taxonomy.
 */
function podcastSchema(string $id): array
{
	$json = file_get_contents(dirname(__DIR__, 4) . '/resources/extensions/totalcms/podcast/schemas/' . $id . '.json');
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

	test('neither is a core reserved schema any more — the extension ships them', function (): void {
		foreach (['podcast', 'podcast-episode'] as $id) {
			expect(SchemaData::RESERVED_SCHEMAS)->not->toContain($id);
			expect(SchemaData::DEFAULT_COLLECTIONS)->not->toContain($id);
		}
	});

	test('the show category field selects from Apple\'s taxonomy', function (): void {
		$categories = podcastSchema('podcast')['properties']['categories'];

		expect($categories['field'])->toBe('list');
		expect($categories['$ref'])->toEndWith('/properties/list.json');
		expect($categories['settings']['propertyOptions'])->toBe('podcastCategories');
	});

	test('the show names its episodes collection from a picker of episode collections', function (): void {
		// A show owns exactly one episodes collection, so the feed is
		// identified by the show alone — that is what lets one site host
		// several shows and what the /feed/{show} route relies on.
		$show     = podcastSchema('podcast');
		$episodes = $show['properties']['episodes'];

		expect($episodes['field'])->toBe('select')
			->and($episodes['settings']['propertyOptions'])->toBe('schemaCollections:podcast-episode')
			->and($show['required'])->toContain('episodes')
			->and($show['index'])->toContain('episodes');
	});

	test('the show schema requires what Apple requires', function (): void {
		expect(podcastSchema('podcast')['required'])->toContain('author', 'ownerEmail', 'cover', 'categories', 'description');
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
