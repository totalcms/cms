<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Orphan\Service\OrphanScanner;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

// OrphanScanner decides what the cleaner is later allowed to delete, so a
// false positive here becomes data loss one API call later. These tests pin
// down which references it calls orphaned — and, just as importantly, which
// it leaves alone.

/**
 * A relational property definition as it appears in a schema.
 *
 * @return array<string,mixed>
 */
function orphanRelProp(string $target, bool $isArray = false, string $value = 'id', string $view = ''): array
{
	$opts = ['collection' => $target, 'value' => $value];
	if ($view !== '') {
		$opts['view'] = $view;
	}

	return [
		'type'     => $isArray ? 'array' : 'string',
		'settings' => ['relationalOptions' => $opts],
	];
}

/**
 * @param array<string,array<string,mixed>>       $schemas  collection => properties
 * @param array<string,array<int,array<string,mixed>>> $indexes  collection => objects
 * @param array<string>                           $missingIndexes collections whose index throws
 */
function orphanScannerFor(array $schemas, array $indexes, array $missingIndexes = []): OrphanScanner
{
	$collections = [];
	foreach (array_keys($schemas) as $id) {
		$c             = new CollectionData();
		$c->id         = $id;
		$collections[] = $c;
	}

	$repo = test()->createMock(CollectionRepository::class);
	$repo->method('listAllCollections')->willReturn($collections);

	$fetcher = test()->createMock(SchemaFetcher::class);
	$fetcher->method('fetchSchemaForCollection')->willReturnCallback(
		function (string $collection) use ($schemas): SchemaData {
			$schema             = new SchemaData();
			$schema->properties = $schemas[$collection] ?? [];

			return $schema;
		}
	);

	$reader = test()->createMock(IndexReader::class);
	$reader->method('fetchIndex')->willReturnCallback(
		function (string $collection) use ($indexes, $missingIndexes): IndexData {
			if (in_array($collection, $missingIndexes, true)) {
				throw new RuntimeException("No index for $collection");
			}

			return new IndexData($indexes[$collection] ?? []);
		}
	);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return new OrphanScanner($repo, $fetcher, $reader, $loggerFactory);
}

describe('OrphanScanner::findRelationalProperties', function (): void {
	it('finds properties that point at another collection', function (): void {
		$scanner = orphanScannerFor([
			'blog' => [
				'title'  => ['type' => 'string'],
				'author' => orphanRelProp('authors'),
			],
		], []);

		$found = $scanner->findRelationalProperties();

		expect($found)->toHaveCount(1);
		expect($found[0]['source'])->toBe('blog');
		expect($found[0]['property'])->toBe('author');
		expect($found[0]['target'])->toBe('authors');
		expect($found[0]['isArray'])->toBeFalse();
	});

	it('marks array-typed properties as arrays', function (): void {
		$scanner = orphanScannerFor([
			'blog' => ['tags' => orphanRelProp('tags', isArray: true)],
		], []);

		expect($scanner->findRelationalProperties()[0]['isArray'])->toBeTrue();
	});

	it('skips view-backed relations, which are derived rather than stored', function (): void {
		$scanner = orphanScannerFor([
			'blog' => ['recent' => orphanRelProp('authors', view: 'recent-authors')],
		], []);

		expect($scanner->findRelationalProperties())->toBe([]);
	});

	it('skips a relation with no target collection', function (): void {
		$scanner = orphanScannerFor([
			'blog' => ['author' => orphanRelProp('')],
		], []);

		expect($scanner->findRelationalProperties())->toBe([]);
	});

	it('ignores non-relational properties entirely', function (): void {
		$scanner = orphanScannerFor([
			'blog' => [
				'title' => ['type' => 'string'],
				'body'  => ['type' => 'string', 'settings' => []],
				'count' => ['type' => 'integer', 'settings' => ['relationalOptions' => null]],
			],
		], []);

		expect($scanner->findRelationalProperties())->toBe([]);
	});

	it('narrows to a single collection when filtered', function (): void {
		$scanner = orphanScannerFor([
			'blog'  => ['author' => orphanRelProp('authors')],
			'pages' => ['owner'  => orphanRelProp('authors')],
		], []);

		expect($scanner->findRelationalProperties())->toHaveCount(2);

		$filtered = $scanner->findRelationalProperties('pages');
		expect($filtered)->toHaveCount(1);
		expect($filtered[0]['source'])->toBe('pages');
	});
});

describe('OrphanScanner::scanAll', function (): void {
	it('reports a scalar reference whose target no longer exists', function (): void {
		$scanner = orphanScannerFor(
			['blog' => ['author' => orphanRelProp('authors')]],
			[
				'blog'    => [['id' => 'post-1', 'author' => 'ghost']],
				'authors' => [['id' => 'alice']],
			]
		);

		$report  = $scanner->scanAll();
		$entries = $report->getEntries();

		expect($entries)->toHaveCount(1);
		expect($entries[0]->collection)->toBe('blog');
		expect($entries[0]->objectId)->toBe('post-1');
		expect($entries[0]->property)->toBe('author');
		expect($entries[0]->orphanedIds)->toBe(['ghost']);
		expect($report->orphanedReferencesFound)->toBe(1);
	});

	it('leaves a reference alone when the target exists', function (): void {
		$scanner = orphanScannerFor(
			['blog' => ['author' => orphanRelProp('authors')]],
			[
				'blog'    => [['id' => 'post-1', 'author' => 'alice']],
				'authors' => [['id' => 'alice']],
			]
		);

		$report = $scanner->scanAll();

		expect($report->isEmpty())->toBeTrue();
		expect($report->orphanedReferencesFound)->toBe(0);
	});

	it('reports only the missing ids from an array property', function (): void {
		$scanner = orphanScannerFor(
			['blog' => ['authors' => orphanRelProp('authors', isArray: true)]],
			[
				'blog'    => [['id' => 'post-1', 'authors' => ['alice', 'ghost', 'bob', 'gone']]],
				'authors' => [['id' => 'alice'], ['id' => 'bob']],
			]
		);

		$entries = $scanner->scanAll()->getEntries();

		expect($entries)->toHaveCount(1);
		expect($entries[0]->orphanedIds)->toBe(['ghost', 'gone']);
		expect($entries[0]->isArray)->toBeTrue();
	});

	it('treats every reference as orphaned when the target collection has no index', function (): void {
		$scanner = orphanScannerFor(
			['blog' => ['author' => orphanRelProp('authors')]],
			['blog' => [['id' => 'post-1', 'author' => 'alice']]],
			missingIndexes: ['authors']
		);

		$entries = $scanner->scanAll()->getEntries();

		expect($entries)->toHaveCount(1);
		expect($entries[0]->orphanedIds)->toBe(['alice']);
	});

	it('skips empty values rather than reporting them as orphans', function (): void {
		$scanner = orphanScannerFor(
			[
				'blog' => [
					'author'  => orphanRelProp('authors'),
					'authors' => orphanRelProp('authors', isArray: true),
				],
			],
			[
				'blog' => [
					['id' => 'a', 'author' => null, 'authors' => []],
					['id' => 'b', 'author' => '',   'authors' => []],
					['id' => 'c'],
				],
				'authors' => [['id' => 'alice']],
			]
		);

		expect($scanner->scanAll()->isEmpty())->toBeTrue();
	});

	it('matches against the configured value field, not always id', function (): void {
		$scanner = orphanScannerFor(
			['blog' => ['author' => orphanRelProp('authors', value: 'slug')]],
			[
				'blog'    => [['id' => 'post-1', 'author' => 'alice-a']],
				'authors' => [['id' => 'a1', 'slug' => 'alice-a']],
			]
		);

		expect($scanner->scanAll()->isEmpty())->toBeTrue();
	});

	it('counts what it scanned', function (): void {
		$scanner = orphanScannerFor(
			[
				'blog'  => ['author' => orphanRelProp('authors')],
				'pages' => ['owner'  => orphanRelProp('authors')],
			],
			[
				'blog'    => [['id' => 'post-1', 'author' => 'ghost'], ['id' => 'post-2', 'author' => 'alice']],
				'pages'   => [['id' => 'page-1', 'owner' => 'alice']],
				'authors' => [['id' => 'alice']],
			]
		);

		$report = $scanner->scanAll();

		expect($report->relationalPropertiesFound)->toBe(2);
		expect($report->collectionsScanned)->toBe(2);
		expect($report->objectsScanned)->toBe(3);
		expect($report->orphanedReferencesFound)->toBe(1);
	});

	it('returns an empty report when nothing is relational', function (): void {
		$scanner = orphanScannerFor(['blog' => ['title' => ['type' => 'string']]], []);

		$report = $scanner->scanAll();

		expect($report->isEmpty())->toBeTrue();
		expect($report->relationalPropertiesFound)->toBe(0);
		expect($report->objectsScanned)->toBe(0);
	});
});

describe('OrphanScanner::scanCollection', function (): void {
	it('scans only the named collection', function (): void {
		$scanner = orphanScannerFor(
			[
				'blog'  => ['author' => orphanRelProp('authors')],
				'pages' => ['owner'  => orphanRelProp('authors')],
			],
			[
				'blog'    => [['id' => 'post-1', 'author' => 'ghost']],
				'pages'   => [['id' => 'page-1', 'owner' => 'also-ghost']],
				'authors' => [['id' => 'alice']],
			]
		);

		$entries = $scanner->scanCollection('pages')->getEntries();

		expect($entries)->toHaveCount(1);
		expect($entries[0]->collection)->toBe('pages');
	});
});
