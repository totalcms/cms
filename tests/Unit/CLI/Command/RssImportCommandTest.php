<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\RssImportCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	// Default: license allows RSS Import. Individual tests override.
	$editionFeatures = $this->createMock(EditionFeatureService::class);
	$editionFeatures->method('can')->willReturnCallback(
		fn (EditionFeature $f): bool => $f === EditionFeature::RSS_IMPORT,
	);
	$this->totalcms->method('editionFeatures')->willReturn($editionFeatures);

	// Default: target collection exists.
	$collectionFetcher = $this->createMock(CollectionFetcher::class);
	$collectionFetcher->method('collectionExists')->willReturnCallback(
		fn (string $id): bool => $id === 'blog',
	);
	$this->totalcms->method('collectionFetcher')->willReturn($collectionFetcher);
});

it('queues feed entries with default options', function (): void {
	$importer = $this->createMock(RssImporter::class);
	$importer->expects($this->once())
		->method('import')
		->with(
			'https://example.com/feed.xml',
			'blog',
			['draft' => true, 'fieldMap' => []],
		)
		->willReturn(7);
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
	]);

	expect($tester->getDisplay())->toContain("Queued 7 item(s) for import into 'blog'");
	expect($tester->getStatusCode())->toBe(0);
});

it('honors --no-draft to publish immediately', function (): void {
	$importer = $this->createMock(RssImporter::class);
	$importer->expects($this->once())
		->method('import')
		->with(
			$this->anything(),
			$this->anything(),
			$this->callback(fn (array $opts): bool => $opts['draft'] === false),
		)
		->willReturn(1);
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
		'--no-draft' => true,
	]);

	expect($tester->getStatusCode())->toBe(0);
});

it('parses repeated --map arguments', function (): void {
	$importer = $this->createMock(RssImporter::class);
	$importer->expects($this->once())
		->method('import')
		->with(
			$this->anything(),
			$this->anything(),
			$this->callback(fn (array $opts): bool => $opts['fieldMap'] === [
				'title'   => 'heading',
				'content' => 'body',
				'image'   => 'featured',
			]),
		)
		->willReturn(0);
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
		'--map'      => ['title=heading', 'content=body', 'image=featured'],
	]);

	expect($tester->getStatusCode())->toBe(0);
});

it('parses comma-separated --map values', function (): void {
	// CSV form keeps cron one-liners short.
	$importer = $this->createMock(RssImporter::class);
	$importer->expects($this->once())
		->method('import')
		->with(
			$this->anything(),
			$this->anything(),
			$this->callback(fn (array $opts): bool => $opts['fieldMap'] === [
				'title'   => 'heading',
				'content' => 'body',
			]),
		)
		->willReturn(0);
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
		'--map'      => ['title=heading,content=body'],
	]);

	expect($tester->getStatusCode())->toBe(0);
});

it('allows mapping a field to empty to drop it', function (): void {
	// `--map link=` writes an empty string, which the importer's mapFields
	// treats as "skip this field" (existing behavior — see RssImporter::mapFields).
	$importer = $this->createMock(RssImporter::class);
	$importer->expects($this->once())
		->method('import')
		->with(
			$this->anything(),
			$this->anything(),
			$this->callback(fn (array $opts): bool => ($opts['fieldMap']['link'] ?? null) === ''),
		)
		->willReturn(0);
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
		'--map'      => ['link='],
	]);

	expect($tester->getStatusCode())->toBe(0);
});

it('rejects --map values without an equals sign', function (): void {
	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
		'--map'      => ['titleheading'],
	]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('Invalid --map');
});

it('returns an error for a nonexistent collection', function (): void {
	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'does-not-exist',
	]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain("Collection 'does-not-exist' does not exist");
});

it('refuses to run on a license edition without RSS Import', function (): void {
	// Override the beforeEach license stub with a no-RSS license.
	$totalcms = $this->createMock(TotalCMS::class);
	$blocked  = $this->createMock(EditionFeatureService::class);
	$blocked->method('can')->willReturn(false);
	$totalcms->method('editionFeatures')->willReturn($blocked);

	$tester = makeTester($totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
	]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('RSS Import is not available');
});

it('surfaces importer exceptions as command failures', function (): void {
	$importer = $this->createMock(RssImporter::class);
	$importer->method('import')->willThrowException(new \RuntimeException('HTTP 500 fetching feed'));
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
	]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('Import failed');
	expect($tester->getDisplay())->toContain('HTTP 500');
});

it('outputs JSON with --json', function (): void {
	$importer = $this->createMock(RssImporter::class);
	$importer->method('import')->willReturn(4);
	$this->totalcms->method('rssImporter')->willReturn($importer);

	$tester = makeTester($this->totalcms);
	$tester->execute([
		'url'        => 'https://example.com/feed.xml',
		'collection' => 'blog',
		'--map'      => ['title=heading'],
		'--json'     => true,
	]);

	$data = json_decode($tester->getDisplay(), true);
	expect($data['success'])->toBeTrue();
	expect($data['imported'])->toBe(4);
	expect($data['collection'])->toBe('blog');
	expect($data['draft'])->toBeTrue();
	expect($data['fieldMap'])->toBe(['title' => 'heading']);
});

function makeTester(TotalCMS $totalcms): CommandTester
{
	$app     = new Application();
	$command = new RssImportCommand($totalcms);
	$app->addCommand($command);

	return new CommandTester($command);
}
