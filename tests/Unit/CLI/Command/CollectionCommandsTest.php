<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionGetCommand;
use TotalCMS\CLI\Command\CollectionListCommand;
use TotalCMS\CLI\Command\CollectionQueryCommand;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$this->col1               = new CollectionData();
	$this->col1->id           = 'blog';
	$this->col1->name         = 'Blog';
	$this->col1->schema       = 'blog';
	$this->col1->category     = 'Content';
	$this->col1->totalObjects = 10;

	$this->col2               = new CollectionData();
	$this->col2->id           = 'products';
	$this->col2->name         = 'Products';
	$this->col2->schema       = 'products';
	$this->col2->category     = 'Commerce';
	$this->col2->totalObjects = 5;

	$collectionLister = $this->createMock(CollectionLister::class);
	$collectionLister->method('listAllCollections')->willReturn([$this->col1, $this->col2]);
	$collectionLister->method('listCollectionsWithSchema')->willReturnCallback(
		fn (string $schema): array => match ($schema) {
			'blog'     => [$this->col1],
			'products' => [$this->col2],
			default    => [],
		}
	);
	$this->totalcms->method('collectionLister')->willReturn($collectionLister);

	$collectionFetcher = $this->createMock(CollectionFetcher::class);
	$collectionFetcher->method('collectionExists')->willReturnCallback(
		fn (string $id): bool => in_array($id, ['blog', 'products'], true)
	);
	$collectionFetcher->method('fetchCollection')->willReturnCallback(
		fn (string $id): ?CollectionData => match ($id) {
			'blog'     => $this->col1,
			'products' => $this->col2,
			default    => null,
		}
	);
	$this->totalcms->method('collectionFetcher')->willReturn($collectionFetcher);
});

describe('collection:list', function (): void {
	beforeEach(function (): void {
		$app     = new Application();
		$command = new CollectionListCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('lists all collections', function (): void {
		$this->tester->execute([]);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('blog');
		expect($output)->toContain('products');
	});

	it('outputs JSON with --json', function (): void {
		$this->tester->execute(['--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(2);
		expect($data[0]['id'])->toBe('blog');
	});

	it('filters by --schema', function (): void {
		$this->tester->execute(['--schema' => 'products', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(1);
		expect($data[0]['id'])->toBe('products');
	});

	it('filters by --category', function (): void {
		$this->tester->execute(['--category' => 'Content', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data)->toHaveCount(1);
		expect($data[0]['id'])->toBe('blog');
	});
});

describe('collection:get', function (): void {
	beforeEach(function (): void {
		$app     = new Application();
		$command = new CollectionGetCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('shows collection details', function (): void {
		$this->tester->execute(['id' => 'blog']);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('Collection: blog');
		expect($output)->toContain('Blog');
	});

	it('outputs JSON with --json', function (): void {
		$this->tester->execute(['id' => 'blog', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data['id'])->toBe('blog');
		expect($data['name'])->toBe('Blog');
	});

	it('returns error for nonexistent collection', function (): void {
		$this->tester->execute(['id' => 'nonexistent']);

		expect($this->tester->getStatusCode())->toBe(1);
	});
});

describe('collection:query', function (): void {
	beforeEach(function (): void {
		$queryResult = new QueryResult(
			items: [
				['id' => 'post-1', 'title' => 'First Post', 'draft' => false],
				['id' => 'post-2', 'title' => 'Second Post', 'draft' => true],
			],
			total: 10,
			limit: 20,
			offset: 0,
		);

		$indexQueryService = $this->createMock(IndexQueryService::class);
		$indexQueryService->method('query')->willReturn($queryResult);
		$this->totalcms->method('indexQueryService')->willReturn($indexQueryService);

		$app     = new Application();
		$command = new CollectionQueryCommand($this->totalcms);
		$app->addCommand($command);
		$this->tester = new CommandTester($command);
	});

	it('shows query results in table format', function (): void {
		$this->tester->execute(['id' => 'blog']);

		$output = $this->tester->getDisplay();
		expect($output)->toContain('First Post');
		expect($output)->toContain('Second Post');
		expect($output)->toContain('of 10 results');
	});

	it('outputs JSON with --json', function (): void {
		$this->tester->execute(['id' => 'blog', '--json' => true]);

		$data = json_decode($this->tester->getDisplay(), true);
		expect($data['total'])->toBe(10);
		expect($data['results'])->toHaveCount(2);
		expect($data['results'][0]['id'])->toBe('post-1');
	});

	it('returns error for nonexistent collection', function (): void {
		$this->tester->execute(['id' => 'nonexistent']);

		expect($this->tester->getStatusCode())->toBe(1);
	});

	it('passes filter params to query service', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		$collectionFetcher->method('collectionExists')->willReturn(true);
		$totalcms->method('collectionFetcher')->willReturn($collectionFetcher);

		$indexQueryService = $this->createMock(IndexQueryService::class);
		$indexQueryService->expects($this->once())
			->method('query')
			->with('blog', $this->callback(function (array $params): bool {
				expect($params)->toHaveKey('include');
				expect($params['include'])->toBe('featured:true');
				expect($params)->toHaveKey('sort');
				expect($params['sort'])->toBe('-date');

				return true;
			}))
			->willReturn(new QueryResult([], 0, 20, 0));
		$totalcms->method('indexQueryService')->willReturn($indexQueryService);

		$app     = new Application();
		$command = new CollectionQueryCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute([
			'id'        => 'blog',
			'--include' => 'featured:true',
			'--sort'    => '-date',
			'--json'    => true,
		]);
	});
});
