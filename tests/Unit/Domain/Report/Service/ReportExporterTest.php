<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\BooleanData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Report\Service\ReportExporter;
use TotalCMS\Factory\LoggerFactory;

/**
 * ReportExporter powers /report/collections/{c}/{json,csv}. It has three
 * non-trivial concerns: param parsing (commas, arrays, legacy filter→include),
 * field filtering (scalars vs deck dot-notation), and CSV deck expansion
 * (one row per deck item, scalar columns repeated).
 */
describe('ReportExporter', function (): void {
	beforeEach(function (): void {
		$this->storage       = $this->createMock(IndexRepository::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->indexFilter   = $this->createMock(IndexFilter::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->createMock(LoggerInterface::class));

		$this->exporter = new ReportExporter(
			$this->storage,
			$this->objectFetcher,
			$this->indexFilter,
			$loggerFactory,
		);
	});

	function makeObject(string $id, array $data): ObjectData
	{
		return new ObjectData($id, $data);
	}

	// --- parseParams ---

	test('parseParams splits a comma-separated fields string', function (): void {
		$result = $this->exporter->parseParams(['fields' => 'title, date ,author']);

		expect($result['fields'])->toBe(['title', 'date', 'author']);
		expect($result['options'])->toBe([]);
	});

	test('parseParams accepts fields as an array', function (): void {
		$result = $this->exporter->parseParams(['fields' => [' title ', 'date', 'author ']]);

		expect($result['fields'])->toBe(['title', 'date', 'author']);
	});

	test('parseParams throws when fields is missing', function (): void {
		expect(fn () => $this->exporter->parseParams([]))
			->toThrow(\InvalidArgumentException::class, 'fields');
	});

	test('parseParams throws when fields list is effectively empty', function (): void {
		expect(fn () => $this->exporter->parseParams(['fields' => '  ,  , ']))
			->toThrow(\InvalidArgumentException::class, 'At least one field');
	});

	test('parseParams forwards include and exclude options', function (): void {
		$result = $this->exporter->parseParams([
			'fields'  => 'title',
			'include' => 'draft=false',
			'exclude' => 'archived=true',
		]);

		expect($result['options'])->toBe([
			'include' => 'draft=false',
			'exclude' => 'archived=true',
		]);
	});

	test('parseParams remaps legacy filter param to include', function (): void {
		$result = $this->exporter->parseParams([
			'fields' => 'title',
			'filter' => 'draft=false',
		]);

		expect($result['options']['include'])->toBe('draft=false');
	});

	test('parseParams does not overwrite include when both filter and include are present', function (): void {
		$result = $this->exporter->parseParams([
			'fields'  => 'title',
			'include' => 'real=1',
			'filter'  => 'legacy=1',
		]);

		expect($result['options']['include'])->toBe('real=1');
	});

	// --- exportJson ---

	test('exportJson returns one filtered object per ID with scalar fields', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['a', 'b']);

		$this->objectFetcher->method('fetchObject')->willReturnCallback(fn (string $_c, string $id): ObjectData => match ($id) {
			'a' => makeObject('a', ['title' => new StringData('A'), 'draft' => new BooleanData(false)]),
			'b' => makeObject('b', ['title' => new StringData('B'), 'draft' => new BooleanData(true)]),
		});

		$result = $this->exporter->exportJson('blog', ['id', 'title']);

		expect($result['errors'])->toBe([]);
		expect($result['data'])->toHaveCount(2);
		expect($result['data'][0])->toBe(['id' => 'a', 'title' => 'A']);
		expect($result['data'][1])->toBe(['id' => 'b', 'title' => 'B']);
	});

	test('exportJson includes deck sub-fields via dot notation', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['p1']);

		$this->objectFetcher
			->method('fetchObject')
			->willReturn(makeObject('p1', [
				'title'    => new StringData('Post'),
				'comments' => new DeckData([
					'c_1' => ['id' => 'c_1', 'author' => 'alice', 'body' => 'first'],
					'c_2' => ['id' => 'c_2', 'author' => 'bob', 'body' => 'second'],
				]),
			]));

		$result = $this->exporter->exportJson('blog', ['title', 'comments.author']);

		expect($result['data'][0]['title'])->toBe('Post');
		expect($result['data'][0]['comments']['c_1'])->toBe(['author' => 'alice']);
		expect($result['data'][0]['comments']['c_2'])->toBe(['author' => 'bob']);
		// body should NOT be included (not requested)
		expect($result['data'][0]['comments']['c_1'])->not->toHaveKey('body');
	});

	test('exportJson records object IDs that fail to fetch as errors', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['good', 'bad']);

		$this->objectFetcher->method('fetchObject')->willReturnCallback(function (string $_c, string $id): ObjectData {
			if ($id === 'bad') {
				throw new \RuntimeException('corrupt');
			}

			return makeObject('good', ['title' => new StringData('OK')]);
		});

		$result = $this->exporter->exportJson('blog', ['id']);

		expect($result['errors'])->toBe(['bad']);
		expect($result['data'])->toHaveCount(1);
	});

	// --- exportJsonData (wrapper) ---

	test('exportJsonData returns data directly when no errors', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['a']);
		$this->objectFetcher->method('fetchObject')->willReturn(makeObject('a', []));

		$result = $this->exporter->exportJsonData('blog', ['id']);

		expect($result)->toBe([['id' => 'a']]);
	});

	test('exportJsonData wraps data with errors when some IDs fail', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['a', 'b']);
		$this->objectFetcher->method('fetchObject')->willReturnCallback(function (string $_c, string $id): ObjectData {
			if ($id === 'b') {
				throw new \RuntimeException('nope');
			}

			return makeObject($id, []);
		});

		$result = $this->exporter->exportJsonData('blog', ['id']);

		expect($result)->toHaveKey('data');
		expect($result)->toHaveKey('errors');
		expect($result['errors'])->toBe(['b']);
	});

	// --- exportCsv: headers + rows ---

	test('exportCsv builds headers from scalars then deck dot-notation', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn([]);

		$result = $this->exporter->exportCsv('blog', ['id', 'title', 'comments.author', 'comments.body']);

		expect($result['headers'])->toBe(['id', 'title', 'comments.author', 'comments.body']);
	});

	test('exportCsv emits a single row for scalar-only exports', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['a']);
		$this->objectFetcher
			->method('fetchObject')
			->willReturn(makeObject('a', ['title' => new StringData('Hello')]));

		$result = $this->exporter->exportCsv('blog', ['id', 'title']);

		expect($result['data'])->toHaveCount(1);
		expect($result['data'][0])->toBe(['a', 'Hello']);
	});

	test('exportCsv expands deck items into one row per item with scalars repeated', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['p1']);
		$this->objectFetcher->method('fetchObject')->willReturn(makeObject('p1', [
			'title'    => new StringData('Post'),
			'comments' => new DeckData([
				'c_1' => ['id' => 'c_1', 'author' => 'alice', 'body' => 'first'],
				'c_2' => ['id' => 'c_2', 'author' => 'bob', 'body' => 'second'],
			]),
		]));

		$result = $this->exporter->exportCsv('blog', ['title', 'comments.author', 'comments.body']);

		expect($result['data'])->toHaveCount(2);
		expect($result['data'][0])->toBe(['Post', 'alice', 'first']);
		expect($result['data'][1])->toBe(['Post', 'bob', 'second']);
	});

	test('exportCsv emits a single blank-deck row when the deck property is empty', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['p1']);
		$this->objectFetcher->method('fetchObject')->willReturn(makeObject('p1', [
			'title'    => new StringData('Post'),
			'comments' => new DeckData([]),
		]));

		$result = $this->exporter->exportCsv('blog', ['title', 'comments.author']);

		expect($result['data'])->toHaveCount(1);
		expect($result['data'][0])->toBe(['Post', '']);
	});

	test('exportCsv formats arrays as JSON and booleans as true/false', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['p1']);
		$this->objectFetcher->method('fetchObject')->willReturn(makeObject('p1', [
			'title'  => new StringData('Post'),
			'draft'  => new BooleanData(true),
		]));

		$result = $this->exporter->exportCsv('blog', ['title', 'draft']);

		expect($result['data'][0][1])->toBe('true');
	});

	test('exportCsv escapes newlines in scalar values to literal \\n', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['p1']);
		$this->objectFetcher->method('fetchObject')->willReturn(makeObject('p1', [
			'body' => new StringData("line 1\nline 2"),
		]));

		$result = $this->exporter->exportCsv('blog', ['body']);

		expect($result['data'][0][0])->toBe('line 1\\nline 2');
	});

	// --- exportCsvString ---

	test('exportCsvString produces CSV text with headers + rows', function (): void {
		$this->storage->method('fetchObjectIds')->willReturn(['a']);
		$this->objectFetcher->method('fetchObject')->willReturn(makeObject('a', ['title' => new StringData('Hi')]));

		$result = $this->exporter->exportCsvString('blog', ['id', 'title']);

		expect($result['csv'])->toContain('id,title');
		expect($result['csv'])->toContain('a,Hi');
		expect($result['errors'])->toBe([]);
	});

	// --- getObjectIds filter path ---

	test('uses the IndexFilter when include/exclude options are supplied', function (): void {
		$this->indexFilter
			->expects($this->once())
			->method('fetchFilteredIndex')
			->with('blog', ['include' => 'draft=false'])
			->willReturn([['id' => 'a'], ['id' => 'b']]);

		$this->storage->expects($this->never())->method('fetchObjectIds');

		$this->objectFetcher->method('fetchObject')->willReturnCallback(fn (string $_c, string $id): ObjectData => makeObject($id, []));

		$result = $this->exporter->exportJson('blog', ['id'], ['include' => 'draft=false']);

		expect($result['data'])->toHaveCount(2);
	});
});
