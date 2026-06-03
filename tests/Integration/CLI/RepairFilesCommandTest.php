<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Maintenance\RepairFilesCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('image');
});

function cmdSeedJpeg(string $collection, string $id, string $property, string $filename, int $w = 24, int $h = 16): void
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

function repairCommandTester(object $app): CommandTester
{
	$command     = new RepairFilesCommand($app->getContainer()->get(TotalCMS::class));
	$application = new Application();
	$application->addCommand($command);

	return new CommandTester($command);
}

it('dry-run reports candidates without writing', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('image', ['id' => 'cmd1']);
	cmdSeedJpeg('image', 'cmd1', 'image', 'pic.jpg');

	$tester = repairCommandTester($this->app);
	$tester->execute(['collection' => 'image']);

	expect($tester->getStatusCode())->toBe(0);
	expect($tester->getDisplay())->toContain('would be repaired')->toContain('cmd1');

	// dry-run must not write
	$img = $container->get(ObjectFetcher::class)->fetchObjectFromDisk('image', 'cmd1')->properties->get('image');
	expect($img->name)->toBe('');
});

it('--apply rebuilds the property', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('image', ['id' => 'cmd2']);
	cmdSeedJpeg('image', 'cmd2', 'image', 'restored.jpg', 40, 22);

	$tester = repairCommandTester($this->app);
	$tester->execute(['collection' => 'image', '--apply' => true]);

	expect($tester->getStatusCode())->toBe(0);
	$img = $container->get(ObjectFetcher::class)->fetchObjectFromDisk('image', 'cmd2')->properties->get('image');
	expect($img->name)->toBe('restored.jpg');
});

it('fails on an unknown collection', function (): void {
	$tester = repairCommandTester($this->app);
	$tester->execute(['collection' => 'does-not-exist']);

	expect($tester->getStatusCode())->toBe(1);
});
