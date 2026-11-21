<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Bundled\S3Uploads\Command\PruneCommand;
use TotalCMS\Bundled\S3Uploads\Command\StatsCommand;
use TotalCMS\Bundled\S3Uploads\Lifecycle\PruneService;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\HttpResponse;

require_once __DIR__ . '/helpers.php';

const S3_PRUNE_NOW = 1789000000; // 2026-09-10T00:26:40Z

/** One page of a bucket listing. @param list<array{string,int,int}> $items key, size, age in seconds */
function s3Listing(array $items, ?string $next = null): HttpResponse
{
	$xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
	foreach ($items as [$key, $size, $age]) {
		$xml .= '<Contents><Key>' . htmlspecialchars($key) . '</Key><LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', S3_PRUNE_NOW - $age) . '</LastModified><ETag>"e"</ETag><Size>' . $size . '</Size></Contents>';
	}
	$xml .= $next === null ? '<IsTruncated>false</IsTruncated>' : '<IsTruncated>true</IsTruncated><NextContinuationToken>' . $next . '</NextContinuationToken>';

	return new HttpResponse(200, $xml . '</ListBucketResult>');
}

/**
 * A PruneService over fakes.
 *
 * $with: settings, responses (the bucket's answers), objects (collection => id => stored values;
 * a Throwable in place of the values makes that object unreadable), unlistable (collections whose ids cannot be read).
 *
 * @param array<string,mixed> $with
 */
function s3Prune(PHPUnit\Framework\TestCase $test, array $with = []): object
{
	$mock    = fn (string $class) => (fn () => $this->createMock($class))->call($test);
	$objects = $with['objects'] ?? [];

	$kit          = new stdClass();
	$kit->http    = new RecordingHttpClient(...($with['responses'] ?? []));
	$kit->idReads = [];

	$lister = $mock(CollectionLister::class);
	$lister->method('listAllCollections')->willReturn(array_map(function (string $id): CollectionData {
		$collection     = new CollectionData();
		$collection->id = $id;

		return $collection;
	}, array_keys($objects)));

	// "pages" has no s3 property; every other collection has the shared test properties.
	$schemas = $mock(SchemaFetcher::class);
	$schemas->method('fetchSchemaForCollection')->willReturnCallback(function (string $collection): SchemaData {
		$schema             = new SchemaData();
		$schema->properties = $collection === 'pages' ? ['title' => ['field' => 'text']] : s3TestProperties();

		return $schema;
	});

	$index = $mock(IndexRepository::class);
	$index->method('fetchObjectIdsFromDisk')->willReturnCallback(function (string $collection) use ($kit, $objects, $with): array {
		$kit->idReads[] = $collection;
		if (in_array($collection, $with['unlistable'] ?? [], true)) {
			throw new RuntimeException('cannot read the folder');
		}

		return array_keys($objects[$collection] ?? []);
	});

	$fetcher = $mock(ObjectFetcher::class);
	$fetcher->method('fetchObjectFromDisk')->willReturnCallback(function (string $collection, string $id) use ($objects) {
		$values = $objects[$collection][$id];

		return $values instanceof Throwable ? throw $values : s3Object($id, $values);
	});

	$settings     = s3Settings($with['settings'] ?? []);
	$kit->service = new PruneService(
		$settings,
		s3Client($kit->http, $with['settings'] ?? []),
		new S3Fields($schemas),
		$lister,
		$index,
		$fetcher,
		fn (): int => S3_PRUNE_NOW,
	);
	$kit->deleted = fn (): array => array_map(
		fn (array $r): string => rawurldecode((string)parse_url($r['url'], PHP_URL_PATH)),
		array_values(array_filter($kit->http->requests, fn (array $r): bool => $r['method'] === 'DELETE')),
	);

	return $kit;
}

describe('PruneService', function (): void {
	$day   = 86400;
	$ptr   = fn (string $key): array => ['id' => '0a1b2c3d', 'key' => $key];
	$usual = fn (): array => [
		'blog' => ['post-1' => ['brochure' => $ptr('site/blog/post-1/brochure/0a1b2c3d-kept.pdf'), 'files' => [$ptr('site/blog/post-1/files/0a1b2c3d-in-depot.pdf')]]],
	];
	$listing = fn (): HttpResponse => s3Listing([
		['site/blog/post-1/brochure/0a1b2c3d-kept.pdf', 100, 9 * 86400],
		['site/blog/post-1/files/0a1b2c3d-in-depot.pdf', 200, 9 * 86400],
		['site/blog/post-1/brochure/1a1b2c3d-fresh.pdf', 300, 3600],
		['site/blog/post-1/brochure/2a1b2c3d-orphan.pdf', 400, 9 * 86400],
		['site/blog/gone/brochure/3a1b2c3d-orphan.pdf', 500, 2 * 86400],
	]);

	test('without a prefix nothing is listed or deleted', function () use ($usual): void {
		$kit    = s3Prune($this, ['settings' => ['prefix' => ''], 'objects' => $usual()]);
		$report = $kit->service->run(true);

		expect($report->refused)->toBeTrue()
			->and($report->listed)->toBe(0)
			->and($kit->http->requests)->toBe([]);
	});

	test('the whole bucket can be asked for by name', function () use ($usual): void {
		$kit    = s3Prune($this, ['settings' => ['prefix' => ''], 'objects' => $usual(), 'responses' => [s3Listing([['stray.pdf', 10, 9 * 86400]])]]);
		$report = $kit->service->run(false, 86400, 1000, true);

		expect($report->refused)->toBeFalse()->and($report->orphans)->toBe(1);
	});

	test('a dry run reports old files nothing points at, and deletes none', function () use ($usual, $listing, $day): void {
		$kit    = s3Prune($this, ['objects' => $usual(), 'responses' => [$listing()]]);
		$report = $kit->service->run(false, $day);

		expect($report->listed)->toBe(5)
			->and($report->referenced)->toBe(2)
			->and($report->orphans)->toBe(2)
			->and($report->deleted)->toBe(0)
			->and($report->bytes)->toBe(900)
			->and($report->sample)->toBe(['site/blog/post-1/brochure/2a1b2c3d-orphan.pdf', 'site/blog/gone/brochure/3a1b2c3d-orphan.pdf'])
			->and($report->capped)->toBeFalse()
			->and($report->downgraded)->toBeFalse()
			->and($kit->http->methods())->toBe(['GET'])
			->and($kit->http->requests[0]['url'])->toContain('prefix=site%2F');
	});

	test('delete mode removes exactly those files', function () use ($usual, $listing, $day): void {
		$kit    = s3Prune($this, ['objects' => $usual(), 'responses' => [$listing(), new HttpResponse(204, ''), new HttpResponse(204, '')]]);
		$report = $kit->service->run(true, $day);

		expect($report->deleted)->toBe(2)
			->and($report->bytes)->toBe(900)
			->and(($kit->deleted)())->toBe(['/site/blog/post-1/brochure/2a1b2c3d-orphan.pdf', '/site/blog/gone/brochure/3a1b2c3d-orphan.pdf']);
	});

	test('a longer age keeps the younger orphan', function () use ($usual, $listing, $day): void {
		$report = s3Prune($this, ['objects' => $usual(), 'responses' => [$listing()]])->service->run(false, 7 * $day);

		expect($report->sample)->toBe(['site/blog/post-1/brochure/2a1b2c3d-orphan.pdf']);
	});

	test('a cap stops the run after that many deletes', function () use ($usual, $listing, $day): void {
		$kit    = s3Prune($this, ['objects' => $usual(), 'responses' => [$listing(), new HttpResponse(204, '')]]);
		$report = $kit->service->run(true, $day, 1);

		expect($report->deleted)->toBe(1)->and($report->capped)->toBeTrue()->and(($kit->deleted)())->toHaveCount(1);
	});

	test('a load failure downgrades the run to a dry run', function () use ($usual, $listing, $day): void {
		$objects                 = $usual();
		$objects['blog']['bad']  = new RuntimeException('unreadable');
		$kit    = s3Prune($this, ['objects' => $objects, 'responses' => [$listing()]]);
		$report = $kit->service->run(true, $day);

		expect($report->downgraded)->toBeTrue()
			->and($report->problems)->toBe(['blog/bad could not be read.'])
			->and($report->deleted)->toBe(0)
			->and($report->orphans)->toBe(2)
			->and(($kit->deleted)())->toBe([]);
	});

	test('a collection whose objects cannot be listed downgrades the run too', function () use ($usual, $listing, $day): void {
		$kit    = s3Prune($this, ['objects' => $usual() + ['docs' => []], 'unlistable' => ['docs'], 'responses' => [$listing()]]);
		$report = $kit->service->run(true, $day);

		expect($report->downgraded)->toBeTrue()
			->and($report->problems)->toBe(['The objects of docs could not be listed.'])
			->and(($kit->deleted)())->toBe([]);
	});

	test('collections with no S3 field are not read', function () use ($usual, $listing): void {
		$kit = s3Prune($this, ['objects' => $usual() + ['pages' => ['home' => []]], 'responses' => [$listing()]]);
		$kit->service->run(false);

		expect($kit->idReads)->toBe(['blog']);
	});

	test('a listing spread over pages is followed to the end', function () use ($usual): void {
		$kit = s3Prune($this, ['objects' => $usual(), 'responses' => [
			s3Listing([['site/a/1a1b2c3d-x.pdf', 1, 9 * 86400]], 'next'),
			s3Listing([['site/b/2a1b2c3d-y.pdf', 2, 9 * 86400]]),
		]]);

		expect($kit->service->run(false)->orphans)->toBe(2);
	});

	test('more than fifty orphans are counted but only fifty are listed', function () use ($usual): void {
		$items = [];
		for ($i = 0; $i < 60; $i++) {
			$items[] = [sprintf('site/x/%08x-f.pdf', $i), 1, 9 * 86400];
		}
		$report = s3Prune($this, ['objects' => $usual(), 'responses' => [s3Listing($items)]])->service->run(false);

		expect($report->orphans)->toBe(60)->and($report->sample)->toHaveCount(50);
	});
});

describe('s3:prune', function (): void {
	$objects = ['blog' => ['post-1' => ['brochure' => ['id' => '0a1b2c3d', 'key' => 'site/blog/post-1/brochure/0a1b2c3d-kept.pdf']]]];
	$bucket  = fn (): HttpResponse => s3Listing([['site/blog/post-1/brochure/0a1b2c3d-kept.pdf', 100, 9 * 86400], ['site/old/1a1b2c3d-orphan.pdf', 2048, 9 * 86400]]);

	test('durations read as seconds', function (string $text, ?int $seconds): void {
		expect(PruneCommand::seconds($text))->toBe($seconds);
	})->with([['30m', 1800], ['24h', 86400], ['7d', 604800], ['90', 90], ['90s', 90], ['soon', null], ['', null], ['-1d', null]]);

	test('the default is a dry run that says what it would delete', function () use ($objects, $bucket): void {
		$kit    = s3Prune($this, ['objects' => $objects, 'responses' => [$bucket()]]);
		$tester = new CommandTester(new PruneCommand(fn (): PruneService => $kit->service));

		expect($tester->execute([]))->toBe(0)
			->and($tester->getDisplay())->toContain('Dry run')
			->and($tester->getDisplay())->toContain('1 file')
			->and($tester->getDisplay())->toContain('2 KB')
			->and($tester->getDisplay())->toContain('site/old/1a1b2c3d-orphan.pdf')
			->and($tester->getDisplay())->toContain('--delete')
			->and(($kit->deleted)())->toBe([]);
	});

	test('--delete deletes, and --json prints the report', function () use ($objects, $bucket): void {
		$kit    = s3Prune($this, ['objects' => $objects, 'responses' => [$bucket(), new HttpResponse(204, '')]]);
		$tester = new CommandTester(new PruneCommand(fn (): PruneService => $kit->service));

		expect($tester->execute(['--delete' => true, '--json' => true, '--older-than' => '7d']))->toBe(0);
		$report = json_decode($tester->getDisplay(), true);

		expect($report['deleted'])->toBe(1)
			->and($report['orphans'])->toBe(1)
			->and($report['bytes'])->toBe(2048)
			->and(($kit->deleted)())->toBe(['/site/old/1a1b2c3d-orphan.pdf']);
	});

	test('a bad duration is an error and nothing runs', function () use ($objects): void {
		$kit    = s3Prune($this, ['objects' => $objects]);
		$tester = new CommandTester(new PruneCommand(fn (): PruneService => $kit->service));

		expect($tester->execute(['--older-than' => 'soon']))->toBe(1)
			->and($tester->getDisplay())->toContain('--older-than')
			->and($kit->http->requests)->toBe([]);
	});

	test('a refused run explains the prefix rule', function () use ($objects): void {
		$kit    = s3Prune($this, ['settings' => ['prefix' => ''], 'objects' => $objects]);
		$tester = new CommandTester(new PruneCommand(fn (): PruneService => $kit->service));

		expect($tester->execute(['--delete' => true]))->toBe(1)
			->and($tester->getDisplay())->toContain('No prefix is set');
	});

	test('a bucket that cannot be reached is an error, not a crash', function () use ($objects): void {
		$kit    = s3Prune($this, ['objects' => $objects]);
		$tester = new CommandTester(new PruneCommand(fn (): PruneService => $kit->service));

		expect($tester->execute([]))->toBe(1)
			->and($tester->getDisplay())->toContain('NetworkError');
	});
});

describe('s3:stats', function (): void {
	test('it lists download counts, most downloaded first', function (): void {
		$counter = s3Counter(tcmsTestTempDir('s3-stats'));
		$counter->increment('blog', 'post-1', 'brochure');
		$counter->increment('docs', 'd1', 'files/0a1b2c3d');
		$counter->increment('docs', 'd1', 'files/0a1b2c3d');

		$tester = new CommandTester(new StatsCommand(fn () => $counter));

		expect($tester->execute([]))->toBe(0)
			->and($tester->getDisplay())->toMatch('#docs/d1\s+\|\s+files/0a1b2c3d\s+\|\s+2\s+\|\s+\|#')
			->and($tester->getDisplay())->toMatch('#blog/post-1\s+\|\s+brochure\s+\|\s+1\s+\|\s+1\s+\|#')
			->and(strpos($tester->getDisplay(), 'docs/d1'))->toBeLessThan(strpos($tester->getDisplay(), 'blog/post-1'));

		expect($tester->execute(['collection' => 'blog', '--json' => true]))->toBe(0)
			->and(json_decode($tester->getDisplay(), true))->toBe([['collection' => 'blog', 'id' => 'post-1', 'file' => 'brochure', 'downloads' => 1, 'allTime' => 1]]);
	});

	test('it shows the all-time total beside the current file\'s count', function (): void {
		$counter = s3Counter(tcmsTestTempDir('s3-stats'));
		foreach (['brochure', 'brochure', 'brochure', 'files/0a1b2c3d', 'files/1a1b2c3d', 'gone'] as $key) {
			$counter->increment('blog', 'post-1', $key);
		}
		// The brochure was replaced, one depot file removed, and the third property cleared.
		$counter->drop('blog', 'post-1', ['brochure', 'files/0a1b2c3d', 'gone']);
		$counter->increment('blog', 'post-1', 'brochure');

		$tester = new CommandTester(new StatsCommand(fn () => $counter));
		$tester->execute(['--json' => true]);

		expect(json_decode($tester->getDisplay(), true))->toBe([
			['collection' => 'blog', 'id' => 'post-1', 'file' => 'brochure', 'downloads' => 1, 'allTime' => 4],
			['collection' => 'blog', 'id' => 'post-1', 'file' => 'files (all files)', 'downloads' => 1, 'allTime' => 2],
			['collection' => 'blog', 'id' => 'post-1', 'file' => 'files/1a1b2c3d', 'downloads' => 1, 'allTime' => null],
			// Nothing is stored there now; its history still shows.
			['collection' => 'blog', 'id' => 'post-1', 'file' => 'gone', 'downloads' => 0, 'allTime' => 1],
		]);
	});

	test('with nothing counted yet it says so', function (): void {
		$tester = new CommandTester(new StatsCommand(fn () => s3Counter(tcmsTestTempDir('s3-stats'))));

		expect($tester->execute([]))->toBe(0)->and($tester->getDisplay())->toContain('No downloads counted yet.');
	});
});
