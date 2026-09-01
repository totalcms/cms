<?php

declare(strict_types=1);

use TotalCMS\Domain\Orphan\Data\OrphanEntry;
use TotalCMS\Domain\Orphan\Data\OrphanReport;

// toArray() on these two is the literal JSON body of GET /api/orphan/scan, so
// the shape is a contract with the admin UI, not an internal detail.

describe('OrphanEntry', function (): void {
	it('serialises every field the API exposes', function (): void {
		$entry = new OrphanEntry('blog', 'post-1', 'authors', ['ghost'], true, 'authors');

		expect($entry->toArray())->toBe([
			'collection'       => 'blog',
			'objectId'         => 'post-1',
			'property'         => 'authors',
			'orphanedIds'      => ['ghost'],
			'isArray'          => true,
			'targetCollection' => 'authors',
		]);
	});
});

describe('OrphanReport', function (): void {
	it('starts empty with a scan timestamp', function (): void {
		$report = new OrphanReport();

		expect($report->isEmpty())->toBeTrue();
		expect($report->getEntries())->toBe([]);
		expect($report->scannedAt)->not->toBe('');
	});

	it('counts orphaned ids rather than entries as it collects them', function (): void {
		// One entry can carry several dead references — the summary counts the
		// references, which is what the admin UI reports back to the operator.
		$report = new OrphanReport();
		$report->addEntry(new OrphanEntry('blog', 'post-1', 'authors', ['a', 'b', 'c'], true, 'authors'));
		$report->addEntry(new OrphanEntry('blog', 'post-2', 'editor', ['d'], false, 'authors'));

		expect($report->getEntries())->toHaveCount(2);
		expect($report->orphanedReferencesFound)->toBe(4);
		expect($report->isEmpty())->toBeFalse();
	});

	it('renders a summary block alongside the entries', function (): void {
		$report                            = new OrphanReport();
		$report->collectionsScanned        = 2;
		$report->objectsScanned            = 7;
		$report->relationalPropertiesFound = 3;
		$report->addEntry(new OrphanEntry('blog', 'post-1', 'authors', ['ghost'], true, 'authors'));

		$array = $report->toArray();

		expect($array['summary']['collectionsScanned'])->toBe(2);
		expect($array['summary']['objectsScanned'])->toBe(7);
		expect($array['summary']['relationalPropertiesFound'])->toBe(3);
		expect($array['summary']['orphanedReferencesFound'])->toBe(1);
		expect($array['summary']['isEmpty'])->toBeFalse();
		expect($array['summary']['scannedAt'])->toBe($report->scannedAt);
		expect($array['entries'])->toHaveCount(1);
		expect($array['entries'][0]['objectId'])->toBe('post-1');
	});

	it('reports isEmpty in the summary when nothing was found', function (): void {
		expect((new OrphanReport())->toArray()['summary']['isEmpty'])->toBeTrue();
	});
});
