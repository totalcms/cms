<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionExportCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\TotalCMS;

// `tcms collection:export --format=csv` must produce the same CSV the admin
// export does — one column per card sub-property in dot notation — because
// that is the shape CsvImporter reads back. The command used to build its own
// CSV with nested values JSON-encoded into a single column, so a CLI export
// could not round-trip through the CLI import.
it('exports CSV through ObjectExporter so cards flatten into dot-notation columns', function (): void {
	$exporter = $this->createMock(ObjectExporter::class);
	$exporter->expects($this->once())
		->method('exportAllObjectsForCSv')
		->with('widgets')
		->willReturn([
			'data'   => [
				['id', 'title', 'mycard.label'],
				['w1', 'Widget', 'Card label'],
			],
			'errors' => [],
		]);

	$container = $this->createMock(Container::class);
	$container->method('get')->with(ObjectExporter::class)->willReturn($exporter);

	$fetcher = $this->createMock(CollectionFetcher::class);
	$fetcher->method('collectionExists')->with('widgets')->willReturn(true);

	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);
	$totalcms->method('collectionFetcher')->willReturn($fetcher);

	$command = new CollectionExportCommand($totalcms);
	(new Application())->addCommand($command);
	$tester = new CommandTester($command);
	$tester->execute(['id' => 'widgets', '--format' => 'csv']);

	$csv = $tester->getDisplay();
	expect($csv)->toContain('id,title,mycard.label')
		->and($csv)->toContain('w1,Widget,"Card label"');
});
