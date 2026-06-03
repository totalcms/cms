<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Service\CollectionFileRepairService;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('image');
});

/** Write a small valid JPEG into a property's storage dir (optionally a card/deck subpath). */
function repairSeedJpeg(string $collection, string $id, string $property, string $filename, int $w = 24, int $h = 16, string $subpath = ''): void
{
	$img = imagecreatetruecolor($w, $h);
	imagefilledrectangle($img, 0, 0, (int)($w / 2), $h, imagecolorallocate($img, 200, 30, 30));
	imagefilledrectangle($img, (int)($w / 2), 0, $w, $h, imagecolorallocate($img, 30, 30, 200));
	ob_start();
	imagejpeg($img, null, 90);
	$bytes = (string)ob_get_clean();
	unset($img);

	$dir = objectFilesPath($collection, $id) . '/' . $property . ($subpath !== '' ? '/' . $subpath : '');
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($dir . '/' . $filename, $bytes);
}

/**
 * Create a custom `widgets` collection whose schema nests an `image` (`photo`)
 * and a `file` (`doc`) inside both a card (`mycard`) and a deck (`mydeck`),
 * via a shared `widget-card` child schema.
 */
function createWidgetCollection(Psr\Container\ContainerInterface $container): void
{
	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'widget-card',
		'properties' => [
			'id'    => ['field' => 'id'],
			'photo' => ['field' => 'image'],
			'doc'   => ['field' => 'file'],
			'label' => ['type' => 'string', 'field' => 'text'],
		],
	]);

	$ref = 'https://www.totalcms.co/schemas/custom/widget-card.json';
	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'widgets',
		'properties' => [
			'id'     => ['type' => 'string', 'field' => 'text'],
			'mycard' => ['field' => 'card', '$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'schemaref' => $ref],
			'mydeck' => ['field' => 'deck', '$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'schemaref' => $ref],
		],
		'index' => ['id'],
	]);

	$collection                     = new CollectionData();
	$collection->id                 = 'widgets';
	$collection->name               = 'Widgets';
	$collection->schema             = 'widgets';
	$collection->url                = '';
	$collection->category           = '';
	$collection->labelPlural        = '';
	$collection->labelSingular      = '';
	$collection->groups             = [];
	$collection->sortBy             = 'id';
	$collection->reverseSort        = false;
	$collection->prettyUrl          = false;
	$collection->queueRebuildOnSave = false;

	$container->get(CollectionRepository::class)->saveCollection($collection);
}

it('flags blank image properties with orphaned files (and skips the rest) on a dry-run scan', function (): void {
	$container = $this->app->getContainer();
	$saver     = $container->get(ObjectSaver::class);

	// blanked image (created with no upload) + an orphaned file on disk
	$saver->saveObject('image', ['id' => 'orphan1']);
	repairSeedJpeg('image', 'orphan1', 'image', 'pic.jpg', 20, 14);

	// image WITH data -> never a candidate
	$saver->saveObject('image', ['id' => 'hasdata', 'image' => ['name' => 'real.jpg', 'width' => 5, 'height' => 5, 'mime' => 'image/jpeg']]);

	// blank, but nothing on disk -> "nothing to do"
	$saver->saveObject('image', ['id' => 'blanknofile']);

	$report = $container->get(CollectionFileRepairService::class)->scan('image', new RepairFilters());

	expect($report->repairableCount())->toBe(1);
	expect($report->candidates[0]->objectId)->toBe('orphan1');
	expect($report->candidates[0]->type)->toBe('image');
	expect($report->candidates[0]->applied)->toBeNull(); // dry-run
	expect($report->blankWithoutFiles)->toBe(1);

	// dry-run must not have written anything
	$img = $container->get(ObjectFetcher::class)->fetchObjectFromDisk('image', 'orphan1')->properties->get('image');
	expect($img->name)->toBe('');
});

it('applies the rebuild, restoring image metadata from the orphaned file', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('image', ['id' => 'orphan2']);
	repairSeedJpeg('image', 'orphan2', 'image', 'restored.jpg', 32, 18);

	$report = $container->get(CollectionFileRepairService::class)->apply('image', new RepairFilters());

	expect($report->appliedOkCount())->toBe(1);

	$img = $container->get(ObjectFetcher::class)->fetchObjectFromDisk('image', 'orphan2')->properties->get('image');
	expect($img->name)->toBe('restored.jpg');
	expect($img->transform()['width'])->toBe(32);
	expect($img->transform()['height'])->toBe(18);
});

it('respects the --type filter', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('image', ['id' => 'orphan3']);
	repairSeedJpeg('image', 'orphan3', 'image', 'pic.jpg');

	// file-only filter excludes the image property entirely
	$report = $container->get(CollectionFileRepairService::class)->scan('image', new RepairFilters(types: ['file']));
	expect($report->repairableCount())->toBe(0);
});

it('rebuilds an image nested inside a card from an orphaned file', function (): void {
	$container = $this->app->getContainer();
	createWidgetCollection($container);

	// card present in JSON but the photo child is blank; the file is on disk
	$container->get(ObjectSaver::class)->saveObject('widgets', ['id' => 'w1', 'mycard' => ['label' => 'Hi']]);
	repairSeedJpeg('widgets', 'w1', 'mycard', 'card-pic.jpg', 40, 24, 'photo');

	$report = $container->get(CollectionFileRepairService::class)->apply('widgets', new RepairFilters());

	$photo = array_values(array_filter($report->candidates, fn ($c): bool => $c->subpath === 'photo'));
	expect($photo)->toHaveCount(1);
	expect($photo[0]->path())->toBe('mycard.photo');
	expect($photo[0]->type)->toBe('image');
	expect($photo[0]->applied)->toBeTrue();

	$card = $container->get(ObjectFetcher::class)->fetchObjectFromDisk('widgets', 'w1')->properties->get('mycard');
	expect($card->get('photo')['name'])->toBe('card-pic.jpg');
	expect($card->get('photo')['width'])->toBe(40);
	expect($card->get('label'))->toBe('Hi'); // sibling preserved
});

it('rebuilds file/image children of a deck item discovered on disk', function (): void {
	$container = $this->app->getContainer();
	createWidgetCollection($container);

	// the whole deck was blanked from the JSON; only the files on disk remain
	// (mycard must be a non-empty object — the card schema requires it)
	$container->get(ObjectSaver::class)->saveObject('widgets', ['id' => 'w2', 'mycard' => ['label' => 'x']]);
	repairSeedJpeg('widgets', 'w2', 'mydeck', 'deck-pic.jpg', 36, 20, 'one/photo');
	$docDir = objectFilesPath('widgets', 'w2') . '/mydeck/one/doc';
	mkdir($docDir, 0777, true);
	file_put_contents($docDir . '/notes.pdf', '%PDF-1.4 deck doc');

	$report = $container->get(CollectionFileRepairService::class)->apply('widgets', new RepairFilters());

	$paths = array_map(fn ($c): string => $c->path(), $report->candidates);
	expect($paths)->toContain('mydeck.one.photo')->toContain('mydeck.one.doc');

	$deck = $container->get(ObjectFetcher::class)->fetchObjectFromDisk('widgets', 'w2')->properties->get('mydeck');
	$item = $deck->getItem('one');
	expect($item['photo']['name'])->toBe('deck-pic.jpg');
	expect($item['doc']['name'])->toBe('notes.pdf');
});

it('skips a nested child that already has data', function (): void {
	$container = $this->app->getContainer();
	createWidgetCollection($container);

	$container->get(ObjectSaver::class)->saveObject('widgets', [
		'id'     => 'w3',
		'mycard' => ['photo' => ['name' => 'existing.jpg', 'width' => 5, 'height' => 5, 'mime' => 'image/jpeg']],
	]);
	repairSeedJpeg('widgets', 'w3', 'mycard', 'orphan.jpg', 40, 24, 'photo');

	$report = $container->get(CollectionFileRepairService::class)->scan('widgets', new RepairFilters());

	$photo = array_values(array_filter($report->candidates, fn ($c): bool => $c->subpath === 'photo'));
	expect($photo)->toHaveCount(0); // already populated — never overwritten
});
