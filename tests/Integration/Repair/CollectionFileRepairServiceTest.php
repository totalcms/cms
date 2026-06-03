<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Repair\Data\RepairFilters;
use TotalCMS\Domain\Repair\Service\CollectionFileRepairService;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('image');
});

/** Write a small valid JPEG into a property's storage dir (unique name to avoid clashes). */
function repairSeedJpeg(string $collection, string $id, string $property, string $filename, int $w = 24, int $h = 16): void
{
	$img = imagecreatetruecolor($w, $h);
	imagefilledrectangle($img, 0, 0, (int)($w / 2), $h, imagecolorallocate($img, 200, 30, 30));
	imagefilledrectangle($img, (int)($w / 2), 0, $w, $h, imagecolorallocate($img, 30, 30, 200));
	ob_start();
	imagejpeg($img, null, 90);
	$bytes = (string)ob_get_clean();
	unset($img);

	$dir = objectFilesPath($collection, $id) . '/' . $property;
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($dir . '/' . $filename, $bytes);
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
