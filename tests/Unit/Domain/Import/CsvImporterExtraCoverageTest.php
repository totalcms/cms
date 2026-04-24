<?php

declare(strict_types=1);

use League\Csv\Reader;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

/**
 * Additional coverage for CsvImporter beyond the existing PHPUnit test file.
 * Exercises cleanCsvData, the full import() loop (new vs update modes),
 * job-queue routing, slug normalization, and error isolation.
 */
describe('CsvImporter extra coverage', function (): void {
	beforeEach(function (): void {
		$this->collectionFetcher  = $this->createMock(CollectionFetcher::class);
		$this->objectFetcher      = $this->createMock(ObjectFetcher::class);
		$this->objectImporter     = $this->createMock(ObjectImporter::class);
		$this->indexBuildListener = $this->createMock(IndexBuildListener::class);
		$this->jobQueuer          = $this->createMock(JobQueuer::class);
		$this->logger             = $this->createMock(LoggerInterface::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->importer = new CsvImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$this->objectImporter,
			$this->indexBuildListener,
			new EventDispatcher(new \Psr\Log\NullLogger()),
			$this->jobQueuer,
			$loggerFactory,
		);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		// Both importer methods return ObjectData; stub both globally so tests
		// that don't set a willReturnCallback don't hit the "null returned" error.
		$stub = new ObjectData('stub', []);
		$this->objectImporter->method('importObject')->willReturn($stub);
		$this->objectImporter->method('updateObject')->willReturn($stub);
	});

	// Helpers bound to $this so they can access protected createMock() safely.
	beforeEach(function (): void {
		$this->uploadedFileFrom = function (string $content): UploadedFileInterface {
			$stream = $this->createMock(StreamInterface::class);
			$stream->method('__toString')->willReturn($content);

			$file = $this->createMock(UploadedFileInterface::class);
			$file->method('getStream')->willReturn($stream);

			return $file;
		};
	});

	// --- cleanCsvData (static) ---

	test('cleanCsvData strips columns with empty headers', function (): void {
		$csv = Reader::fromString("id,,name\n1,garbage,alice\n2,extra,bob");
		$csv->setHeaderOffset(0);

		$records = CsvImporter::cleanCsvData($csv);

		expect($records)->toHaveCount(2);
		// The empty-header column is removed entirely
		expect(array_keys($records[0]))->toBe(['id', 'name']);
		expect($records[0])->toBe(['id' => '1', 'name' => 'alice']);
	});

	test('cleanCsvData trims whitespace from values', function (): void {
		$csv = Reader::fromString("id,name\n  1  ,  alice  ");
		$csv->setHeaderOffset(0);

		$records = CsvImporter::cleanCsvData($csv);

		expect($records[0])->toBe(['id' => '1', 'name' => 'alice']);
	});

	test('cleanCsvData drops rows that are entirely empty after trimming', function (): void {
		$csv = Reader::fromString("id,name\n1,alice\n , \n2,bob");
		$csv->setHeaderOffset(0);

		$records = CsvImporter::cleanCsvData($csv);

		expect($records)->toHaveCount(2);
		expect(array_column($records, 'id'))->toBe(['1', '2']);
	});

	// --- import(): happy paths ---

	test('import creates new objects via ObjectImporter', function (): void {
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->objectImporter
			->expects($this->exactly(2))
			->method('importObject');

		$count = $this->importer->import('blog', ($this->uploadedFileFrom)("id,title\npost-1,Hello\npost-2,World"));

		expect($count)->toBe(2);
	});

	test('import slugifies row ids before dispatching', function (): void {
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$seen = [];
		$this->objectImporter
			->method('importObject')
			->willReturnCallback(function (string $_c, array $record) use (&$seen): void {
				$seen[] = $record['id'];
			});

		$this->importer->import('blog', ($this->uploadedFileFrom)("id,title\nHello World,A\n  Another ID  ,B"));

		// Both ids are slugified (lowercased, spaces → hyphens, trimmed)
		expect($seen)->toBe(['hello-world', 'another-id']);
	});

	test('import skips rows whose object already exists (non-update mode)', function (): void {
		$this->objectFetcher
			->method('existsObject')
			->willReturnCallback(fn (string $_c, string $id): bool => $id === 'existing');

		$this->objectImporter
			->expects($this->once())
			->method('importObject')
			->with('blog', $this->callback(fn (array $r): bool => $r['id'] === 'new-one'));

		$count = $this->importer->import('blog', ($this->uploadedFileFrom)("id,title\nexisting,A\nnew-one,B"));

		expect($count)->toBe(1);
	});

	// --- import(): update mode ---

	test('import with updateObject=true only updates existing objects', function (): void {
		$this->objectFetcher
			->method('existsObject')
			->willReturnCallback(fn (string $_c, string $id): bool => $id === 'post-1');

		$this->objectImporter
			->expects($this->once())
			->method('updateObject')
			->with('blog', $this->callback(fn (array $r): bool => $r['id'] === 'post-1'));

		$count = $this->importer->import(
			'blog',
			($this->uploadedFileFrom)("id,title\npost-1,Updated\npost-2,Missing"),
			true,
		);

		expect($count)->toBe(1);
	});

	test('import update mode skips rows with no id column', function (): void {
		$this->objectFetcher->method('existsObject')->willReturn(false);
		$this->objectImporter->expects($this->never())->method('updateObject');

		$count = $this->importer->import(
			'blog',
			($this->uploadedFileFrom)("title\nOrphan"),
			true,
		);

		expect($count)->toBe(0);
	});

	// --- import(): queue mode ---

	test('queueJobs mode routes new records to JobQueuer::queueImport', function (): void {
		$this->importer->queueJobs();
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->jobQueuer->expects($this->exactly(2))->method('queueImport');
		$this->objectImporter->expects($this->never())->method('importObject');

		$count = $this->importer->import('blog', ($this->uploadedFileFrom)("id,title\na,A\nb,B"));

		expect($count)->toBe(2);
	});

	test('queueJobs mode routes updates to JobQueuer::queueUpdate', function (): void {
		$this->importer->queueJobs();
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->jobQueuer->expects($this->once())->method('queueUpdate');
		$this->objectImporter->expects($this->never())->method('updateObject');

		$count = $this->importer->import('blog', ($this->uploadedFileFrom)("id,title\npost-1,A"), true);

		expect($count)->toBe(1);
	});

	// --- import(): error isolation ---

	test('a failing row does not abort the import — subsequent rows still processed', function (): void {
		// Build a separate mock so we can override the global `willReturn` stub
		$failingImporter = $this->createMock(ObjectImporter::class);

		$calls = 0;
		$failingImporter
			->method('importObject')
			->willReturnCallback(function () use (&$calls): ObjectData {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('row-1 boom');
				}

				return new ObjectData('stub', []);
			});

		// Rebuild the importer with the failing mock
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);
		$importer = new CsvImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$failingImporter,
			$this->indexBuildListener,
			new EventDispatcher(new \Psr\Log\NullLogger()),
			$this->jobQueuer,
			$loggerFactory,
		);

		$this->objectFetcher->method('existsObject')->willReturn(false);

		$count = $importer->import('blog', ($this->uploadedFileFrom)("id,title\na,A\nb,B"));

		expect($count)->toBe(1);
		expect($calls)->toBe(2);
	});
});
