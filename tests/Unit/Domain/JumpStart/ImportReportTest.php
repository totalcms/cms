<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\JumpStart\Data\ImportReport;

// The summary used to be recovered by parsing the report's own result
// strings ("Schema x: created", "generated 5 items"). Sections now count as
// they record, with the same totals the string parsing produced.
test('sections tally as they record and the summary keeps its shape', function (): void {
	$report = new ImportReport(new NullLogger());

	$report->result(ImportReport::SCHEMAS, 'Schema products: created');
	$report->result(ImportReport::COLLECTIONS, 'Collection blog: exists');
	$report->result(ImportReport::COLLECTIONS, 'Collection news: updated');
	$report->result(ImportReport::TEMPLATES, 'Template post: created');
	$report->result(ImportReport::OBJECTS, 'Object blog/one: created');
	$report->result(ImportReport::OBJECTS, 'Object blog/two: already exists, skipping');
	$report->result(ImportReport::FACTORY, 'Factory blog/x: generated');
	$report->result(ImportReport::FACTORY, 'Factory blog: generated 5 items', 5);
	$report->result(null, 'Page order builder-pages: applied');
	$report->error('Object blog/three: Collection not found');

	expect($report->summary())->toBe([
		'schemas_created'       => 1,
		'collections_created'   => 2,
		'templates_created'     => 1,
		'objects_created'       => 2,
		'factory_items_created' => 6,
		'total_errors'          => 1,
	])
		->and($report->hasErrors())->toBeTrue()
		->and($report->toData()['results'])->toHaveCount(9)
		->and($report->toData()['errors'])->toBe(['Object blog/three: Collection not found']);
});

test('an untouched report is a clean success', function (): void {
	$report = new ImportReport(new NullLogger());

	expect($report->hasErrors())->toBeFalse()
		->and($report->summary()['total_errors'])->toBe(0)
		->and($report->toData()['results'])->toBe([]);
});
