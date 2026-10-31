<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\ImageWorksWarmCommand;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

use function TotalCMS\Slim\Pest\postUpload;

/**
 * `tcms imageworks:warm` parses options and prints; the work is the warmer's.
 * These tests drive the command over the real app with one collection of two
 * photos, so what is asserted on is the report and the `.cache` folders.
 */
beforeEach(function (): void {
	// execute() applies --memory with ini_set(), which outlives the test.
	$this->memoryLimit = (string)ini_get('memory_limit');

	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$c->get(SchemaSaver::class)->saveSchema(['id' => 'snaps', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
	], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'snaps', 'name' => 'Snaps', 'schema' => 'snaps']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's1']);
	$c->get(ObjectSaver::class)->saveObject('snaps', ['id' => 's2']);

	expect(postUpload('/api/collections/snaps/s1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200)
		->and(postUpload('/api/collections/snaps/s2/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200);

	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($c);
	$totalcms->config = $c->get(Config::class);

	$this->totalcms = $totalcms;
	$this->tester   = new CommandTester(new ImageWorksWarmCommand($totalcms));

	// A collection whose photo has a text watermark, and an edition stand-in.
	$this->watermarked = function () use ($c): void {
		$c->get(SchemaSaver::class)->saveSchema(['id' => 'marked', 'type' => 'object', 'properties' => [
			'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => [
				'extractPalette' => false,
				'watermark'      => ['marktext' => 'Sample'],
			]],
		], 'index' => ['id']]);
		$c->get(CollectionSaver::class)->saveCollection(['id' => 'marked', 'name' => 'Marked', 'schema' => 'marked']);
		$c->get(ObjectSaver::class)->saveObject('marked', ['id' => 'm1']);
		expect(postUpload('/api/collections/marked/m1/photo', testData('test-image.jpg'), 'image/jpeg', 'photo')->getStatusCode())->toBe(200);
	};
	$this->edition = function (bool $watermarks) use ($c): void {
		$editions = $this->createStub(EditionFeatureService::class);
		$editions->method('can')->willReturn($watermarks);
		$editions->method('getEdition')->willReturn($watermarks ? Edition::PRO : Edition::LITE);
		$c->set(EditionFeatureService::class, $editions);
	};
	$this->markedCacheFiles = function (): int {
		$dir = objectFilesPath('marked', 'm1') . '/photo/.cache';
		if (!is_dir($dir)) {
			return 0;
		}
		$count = 0;
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
			$count += $file->isFile() ? 1 : 0;
		}

		return $count;
	};

	$this->cacheFiles = function (): int {
		$count = 0;
		foreach (['s1', 's2'] as $id) {
			$dir = objectFilesPath('snaps', $id) . '/photo/.cache';
			if (!is_dir($dir)) {
				continue;
			}
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
				$count += $file->isFile() ? 1 : 0;
			}
		}

		return $count;
	};
});

afterEach(function (): void {
	ini_set('memory_limit', $this->memoryLimit);
});

test('no variant option fails with the message and does nothing', function (): void {
	$status = $this->tester->execute(['collection' => 'snaps']);

	expect($status)->toBe(Command::FAILURE)
		->and($this->tester->getDisplay())->toContain('--widths')
		->and($this->tester->getDisplay())->toContain('--dry-run')
		->and(($this->cacheFiles)())->toBe(0);
});

test('json output carries the report', function (): void {
	$status = $this->tester->execute(['collection' => 'snaps', '--id' => 's1', '--params' => ['w=200'], '--json' => true]);

	$json = json_decode($this->tester->getDisplay(), true);
	expect($status)->toBe(Command::SUCCESS)
		->and($json['made'])->toBe(1)
		->and($json['images'])->toBe(1)
		->and($json['failed'])->toBe(0);
});

test('dry run says would make and writes nothing', function (): void {
	$status = $this->tester->execute(['collection' => 'snaps', '--params' => ['w=200'], '--dry-run' => true]);

	expect($status)->toBe(Command::SUCCESS)
		->and($this->tester->getDisplay())->toContain('Would make')
		->and(($this->cacheFiles)())->toBe(0);
});

test('a comma list for the grid is split, trimmed and cleaned', function (): void {
	$this->tester->execute(['collection' => 'snaps', '--id' => ' s1, ,s2', '--widths' => '100, 150,', '--formats' => 'webp,', '--json' => true]);

	$json = json_decode($this->tester->getDisplay(), true);
	expect($json['objects'])->toBe(2)
		->and($json['made'])->toBeGreaterThanOrEqual(4);
});

test('a failure sets exit code 1 and lists it', function (): void {
	$name = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObjectFromDisk('snaps', 's2')->toArray()['photo']['name'];
	unlink(objectFilesPath('snaps', 's2') . "/photo/{$name}");

	$status = $this->tester->execute(['collection' => 'snaps', '--params' => ['w=200']]);

	expect($status)->toBe(Command::FAILURE)
		->and($this->tester->getDisplay())->toContain('s2');
});

test('unknown collection or property is an error', function (): void {
	expect($this->tester->execute(['collection' => 'nope', '--params' => ['w=200']]))->toBe(Command::FAILURE);
	expect($this->tester->execute(['collection' => 'snaps', 'property' => 'id', '--params' => ['w=200']]))->toBe(Command::FAILURE);
});

test('a watermarked property under an edition without watermarks is refused and nothing is made', function (): void {
	($this->watermarked)();
	($this->edition)(false);

	$status = $this->tester->execute(['collection' => 'marked', '--params' => ['w=200']]);

	expect($status)->toBe(Command::FAILURE)
		->and($this->tester->getDisplay())->toContain('photo has watermarks, but this run resolved the Lite edition, which doesn\'t include them. Nothing was warmed.')
		->and($this->tester->getDisplay())->toContain('tcms check')
		->and(($this->markedCacheFiles)())->toBe(0);
});

test('the watermark refusal is JSON under --json and applies to --dry-run', function (): void {
	($this->watermarked)();
	($this->edition)(false);

	$status = $this->tester->execute(['collection' => 'marked', '--params' => ['w=200'], '--dry-run' => true, '--json' => true]);

	$json = json_decode($this->tester->getDisplay(), true);
	expect($status)->toBe(Command::FAILURE)
		->and($json['error'])->toContain('photo has watermarks')
		->and(($this->markedCacheFiles)())->toBe(0);
});

test('a watermarked property under an edition with watermarks is warmed', function (): void {
	($this->watermarked)();
	($this->edition)(true);

	$status = $this->tester->execute(['collection' => 'marked', '--params' => ['w=200'], '--json' => true]);

	$json = json_decode($this->tester->getDisplay(), true);
	expect($status)->toBe(Command::SUCCESS)
		->and($json['made'])->toBe(1)
		->and(($this->markedCacheFiles)())->toBeGreaterThan(0);
});

test('an index that cannot be read during the run is a JSON error, not a stack trace', function (): void {
	$c = $this->app->getContainer();
	($this->edition)(true);
	$index = $this->createStub(IndexReader::class);
	$index->method('fetchIndex')->willThrowException(new RuntimeException('index is corrupt'));
	$c->set(IndexReader::class, $index);

	$status = $this->tester->execute(['collection' => 'snaps', '--params' => ['w=200'], '--json' => true]);

	$json = json_decode($this->tester->getDisplay(), true);
	expect($status)->toBe(Command::FAILURE)
		->and($json)->toBeArray()
		->and($json['error'])->toContain('index is corrupt');
});

test('running as someone other than the data folder owner warns before the run', function (): void {
	$owner   = (int)fileowner($this->totalcms->config->datadir);
	$command = new class ($this->totalcms, $owner + 1) extends ImageWorksWarmCommand {
		public function __construct(TotalCMS $totalcms, private readonly int $uid)
		{
			parent::__construct($totalcms);
		}

		protected function effectiveUid(): ?int
		{
			return $this->uid;
		}
	};
	$tester = new CommandTester($command);

	$status = $tester->execute(['collection' => 'snaps', '--id' => 's1', '--params' => ['w=200']]);

	expect($status)->toBe(Command::SUCCESS)
		->and($tester->getDisplay())->toContain('but the data folder belongs to')
		->and($tester->getDisplay())->toContain('sudo -u ');
});

test('running as the data folder owner does not warn', function (): void {
	$this->tester->execute(['collection' => 'snaps', '--id' => 's1', '--params' => ['w=200']]);

	expect($this->tester->getDisplay())->not->toContain('data folder belongs to');
});
