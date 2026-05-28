<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command\Search;

use DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Search\SearchReindexCommand;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeSearchReindexTester(TotalCMS $totalcms): CommandTester
{
	$app     = new Application();
	$command = new SearchReindexCommand($totalcms);
	$app->addCommand($command);

	return new CommandTester($command);
}

/** @param array<int,array<string,mixed>> $objects */
function makeIndexData(array $objects): IndexData
{
	return new IndexData($objects);
}

function makeCollectionData(string $id): CollectionData
{
	$cd         = new CollectionData();
	$cd->id     = $id;
	$cd->schema = 'blog';
	$cd->name   = ucfirst($id);

	return $cd;
}

// ---------------------------------------------------------------------------
// Test setup
// ---------------------------------------------------------------------------

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);
});

// ---------------------------------------------------------------------------
// Test 1: active=text → exits 0, no jobs queued, prints "nothing to reindex"
// ---------------------------------------------------------------------------

it('exits 0 with "nothing to reindex" message when active provider is "text"', function (): void {
	$config         = $this->createMock(Config::class);
	$config->search = ['activeProvider' => 'text'];

	$container = $this->createMock(Container::class);
	$container->method('get')
		->with(Config::class)
		->willReturn($config);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeSearchReindexTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(0);
	expect($tester->getDisplay())->toContain('nothing to reindex');
});

// ---------------------------------------------------------------------------
// Test 2: active=algolia but provider not registered → exits 1, error message
// ---------------------------------------------------------------------------

it('exits 1 with error when active provider is not registered', function (): void {
	$config         = $this->createMock(Config::class);
	$config->search = ['activeProvider' => 'algolia'];

	// Empty registry — 'algolia' is not in it
	$registry = new SearchProviderRegistry();

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			Config::class                 => $config,
			SearchProviderRegistry::class => $registry,
			default                       => null,
		}
	);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeSearchReindexTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('not registered');
});

// ---------------------------------------------------------------------------
// Test 3: no collection argument and no --all → exits 1, error message
// ---------------------------------------------------------------------------

it('exits 1 with error when no collection and no --all given', function (): void {
	$config         = $this->createMock(Config::class);
	$config->search = ['activeProvider' => 'algolia'];

	$provider = $this->createMock(SearchProvider::class);
	$provider->method('id')->willReturn('algolia');

	$registry = new SearchProviderRegistry();
	$registry->register($provider);

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			Config::class                 => $config,
			SearchProviderRegistry::class => $registry,
			default                       => null,
		}
	);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeSearchReindexTester($this->totalcms);
	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
	expect($tester->getDisplay())->toContain('Specify a collection id or pass --all');
});

// ---------------------------------------------------------------------------
// Test 4: single collection argument → queues one job per object
// ---------------------------------------------------------------------------

it('queues one ReindexJob per object for a single collection argument', function (): void {
	$config         = $this->createMock(Config::class);
	$config->search = ['activeProvider' => 'algolia'];

	$provider = $this->createMock(SearchProvider::class);
	$provider->method('id')->willReturn('algolia');

	$registry = new SearchProviderRegistry();
	$registry->register($provider);

	$indexData = makeIndexData([
		['id' => 'post-1', 'title' => 'First Post'],
		['id' => 'post-2', 'title' => 'Second Post'],
		['id' => '',       'title' => 'No ID — should be skipped'],
	]);

	$reader = $this->createMock(IndexReader::class);
	$reader->method('fetchIndex')->with('blog')->willReturn($indexData);

	$queued    = [];
	$jobQueuer = $this->createMock(JobQueuer::class);
	$jobQueuer->method('queueJob')
		->willReturnCallback(function (string $type, string $collection, array $data) use (&$queued): JobData {
			$queued[] = ['type' => $type, 'collection' => $collection, 'data' => $data];

			return new JobData();
		});

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			Config::class                 => $config,
			SearchProviderRegistry::class => $registry,
			IndexReader::class            => $reader,
			JobQueuer::class              => $jobQueuer,
			CollectionRepository::class   => null,
			default                       => null,
		}
	);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeSearchReindexTester($this->totalcms);
	$tester->execute(['collection' => 'blog']);

	expect($tester->getStatusCode())->toBe(0);
	// Two objects queued (empty-id object skipped)
	expect($queued)->toHaveCount(2);
	expect($queued[0]['type'])->toBe(JobData::TYPE_SEARCH_REINDEX);
	expect($queued[0]['collection'])->toBe('blog');
	expect($queued[0]['data']['object_id'])->toBe('post-1');
	expect($queued[0]['data']['operation'])->toBe('index');
	expect($queued[1]['data']['object_id'])->toBe('post-2');

	$display = $tester->getDisplay();
	expect($display)->toContain('blog: 2 objects queued');
	expect($display)->toContain('Queued 2 ReindexJob entries');
	expect($display)->toContain('algolia');
});

// ---------------------------------------------------------------------------
// Test 5: --all flag → walks every collection from CollectionRepository
// ---------------------------------------------------------------------------

it('queues jobs for every collection when --all is passed', function (): void {
	$config         = $this->createMock(Config::class);
	$config->search = ['activeProvider' => 'algolia'];

	$provider = $this->createMock(SearchProvider::class);
	$provider->method('id')->willReturn('algolia');

	$registry = new SearchProviderRegistry();
	$registry->register($provider);

	$cd1 = makeCollectionData('blog');
	$cd2 = makeCollectionData('portfolio');

	$collectionRepo = $this->createMock(CollectionRepository::class);
	$collectionRepo->method('listAllCollections')->willReturn([$cd1, $cd2]);

	$reader = $this->createMock(IndexReader::class);
	$reader->method('fetchIndex')->willReturnCallback(
		fn (string $id): IndexData => match ($id) {
			'blog'      => makeIndexData([['id' => 'b1'], ['id' => 'b2']]),
			'portfolio' => makeIndexData([['id' => 'p1']]),
			default     => makeIndexData([]),
		}
	);

	$queued    = [];
	$jobQueuer = $this->createMock(JobQueuer::class);
	$jobQueuer->method('queueJob')
		->willReturnCallback(function (string $type, string $collection, array $data) use (&$queued): JobData {
			$queued[] = ['type' => $type, 'collection' => $collection, 'data' => $data];

			return new JobData();
		});

	$container = $this->createMock(Container::class);
	$container->method('get')->willReturnCallback(
		fn (string $class): mixed => match ($class) {
			Config::class                 => $config,
			SearchProviderRegistry::class => $registry,
			CollectionRepository::class   => $collectionRepo,
			IndexReader::class            => $reader,
			JobQueuer::class              => $jobQueuer,
			default                       => null,
		}
	);

	$this->totalcms->method('container')->willReturn($container);

	$tester = makeSearchReindexTester($this->totalcms);
	$tester->execute(['--all' => true]);

	expect($tester->getStatusCode())->toBe(0);
	// 2 blog + 1 portfolio = 3 total
	expect($queued)->toHaveCount(3);

	$collections = array_column($queued, 'collection');
	expect(in_array('blog', $collections, true))->toBeTrue();
	expect(in_array('portfolio', $collections, true))->toBeTrue();

	$display = $tester->getDisplay();
	expect($display)->toContain('blog: 2 objects queued');
	expect($display)->toContain('portfolio: 1 objects queued');
	expect($display)->toContain('Queued 3 ReindexJob entries');
});
