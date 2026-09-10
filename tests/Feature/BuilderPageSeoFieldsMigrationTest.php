<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionRemover;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Migration\Migration\BuilderPageSeoFieldsMigration;
use TotalCMS\Domain\Migration\Repository\MigrationStateRepository;
use TotalCMS\Domain\Migration\Service\MigrationRunner;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Records what the migration logs. Named for this file so it can't collide
 * with the other recording loggers when paratest lands two files in one worker.
 *
 * @internal
 */
final class MigrationRecordingLogger extends AbstractLogger
{
	/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
	public array $records = [];

	/** @param array<string,mixed> $context */
	public function log(mixed $level, string|\Stringable $message, array $context = []): void
	{
		$this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
	}

	public function hasWarning(string $fragment): bool
	{
		foreach ($this->records as $record) {
			if ($record['level'] === 'warning' && str_contains($record['message'], $fragment)) {
				return true;
			}
		}

		return false;
	}
}

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();
	$this->container->get(BuilderInstaller::class)->ensurePagesCollection();

	// Write a pre-3.5.1 page record straight to disk: the saver would run it
	// through the (already migrated) schema and drop the very keys under test.
	$this->legacyPage = function (string $id, array $overrides = []): void {
		$record = array_merge([
			'id'          => $id,
			'title'       => 'About Us',
			'route'       => "/{$id}",
			'template'    => 'pages/about.twig',
			'description' => 'Legacy meta description',
			'image'       => [
				'name'   => 'test-image.jpg',
				'size'   => 12345,
				'mime'   => 'image/jpeg',
				'width'  => 1920,
				'height' => 1080,
				'alt'    => 'Legacy alt',
			],
			'draft'   => false,
			'nav'     => true,
			'updated' => '2026-01-01T00:00:00+00:00',
			'created' => '2026-01-01T00:00:00+00:00',
		], $overrides);

		file_put_contents(objectPath('builder-pages', $id), json_encode($record, JSON_PRETTY_PRINT));

		if (($record['image']['name'] ?? '') !== '') {
			$dir = objectFilesPath('builder-pages', $id) . '/image';
			mkdir($dir, 0755, true);
			copy(testData('test-image.jpg'), $dir . '/' . $record['image']['name']);
		}

		$this->container->get(IndexBuilder::class)->buildIndex('builder-pages');
	};

	// Built by hand rather than pulled from the container so a test can hand it
	// a recording logger; every other collaborator is the container's own.
	$this->migration = function (?LoggerInterface $logger = null): BuilderPageSeoFieldsMigration {
		$c = $this->container;

		return new BuilderPageSeoFieldsMigration(
			$c->get(BuilderConfigService::class),
			$c->get(IndexReader::class),
			$c->get(IndexBuilder::class),
			$c->get(ObjectRepository::class),
			$c->get(ObjectFileCodec::class),
			$c->get(ObjectFetcher::class),
			$c->get(ObjectUpdater::class),
			$c->get(Config::class),
			$logger ?? new NullLogger(),
		);
	};
});

it('moves a legacy page description and image into the seo card', function (): void {
	($this->legacyPage)('about');

	$migration = ($this->migration)();
	expect($migration->id())->toBe('builder-page-seo-fields');
	expect($migration->run())->toBe(1);

	$page = $this->container->get(ObjectFetcher::class)->fetchObject('builder-pages', 'about')->toArray();

	expect($page['seo']['description'] ?? '')->toBe('Legacy meta description')
		->and($page['seo']['image']['name'] ?? '')->toBe('test-image.jpg')
		->and($page['seo']['image']['alt'] ?? '')->toBe('Legacy alt');

	// The re-save goes through the schema, which no longer declares the two
	// top-level properties — so the stored record loses them too.
	$stored = json_decode((string)file_get_contents(objectPath('builder-pages', 'about')), true);
	expect($stored)->not->toHaveKey('description')
		->and($stored)->not->toHaveKey('image');

	// The files followed the value into the card's nested directory.
	$files = objectFilesPath('builder-pages', 'about');
	expect(is_file($files . '/seo/image/test-image.jpg'))->toBeTrue()
		->and(is_dir($files . '/image'))->toBeFalse();

	$path = $this->container->get(MediaTwigAdapter::class)
		->imagePath($page, [], ['collection' => 'builder-pages', 'property' => 'seo.image']);
	expect($path)->not->toBe('')
		->and($path)->toContain('/imageworks/builder-pages/about/seo/image.jpg');
});

it('is idempotent — a second run changes nothing', function (): void {
	($this->legacyPage)('about');

	expect(($this->migration)()->run())->toBe(1);
	expect(($this->migration)()->run())->toBe(0);
});

it('never overwrites values the card already carries', function (): void {
	($this->legacyPage)('about', [
		'seo' => [
			'description' => 'Card wins',
			'image'       => ['name' => 'card.jpg', 'size' => 99, 'mime' => 'image/jpeg', 'alt' => 'Card alt'],
		],
	]);

	expect(($this->migration)()->run())->toBe(0);

	$page = $this->container->get(ObjectFetcher::class)->fetchObject('builder-pages', 'about')->toArray();
	expect($page['seo']['description'] ?? '')->toBe('Card wins')
		->and($page['seo']['image']['name'] ?? '')->toBe('card.jpg');
});

it('copies the description alone when the page has no image', function (): void {
	($this->legacyPage)('terms', ['image' => ['name' => '', 'size' => 0]]);

	expect(($this->migration)()->run())->toBe(1);

	$page = $this->container->get(ObjectFetcher::class)->fetchObject('builder-pages', 'terms')->toArray();
	expect($page['seo']['description'] ?? '')->toBe('Legacy meta description')
		->and($page['seo']['image']['name'] ?? '')->toBe('');
});

it('returns 0 when there is no pages collection', function (): void {
	$this->container->get(CollectionRemover::class)->deleteCollection('builder-pages');

	expect(($this->migration)()->run())->toBe(0);
});

it('leaves the page alone, warns, and throws for a retry when the image files cannot be moved', function (): void {
	($this->legacyPage)('about');
	// A sibling that migrates cleanly: the failure must not cost it its move.
	($this->legacyPage)('terms');

	// Occupy the card's image path with a FILE, so rename() of the legacy
	// directory onto it fails the way a permissions problem would.
	$files = objectFilesPath('builder-pages', 'about');
	mkdir($files . '/seo', 0755, true);
	file_put_contents($files . '/seo/image', 'not a directory');

	$logger = new MigrationRecordingLogger();

	// A failed MOVE is the one condition that throws: the runner leaves a
	// throwing migration unrecorded, so the next request retries the page.
	expect(fn (): int => ($this->migration)($logger)->run())
		->toThrow(RuntimeException::class, 'about');
	expect($logger->hasWarning('could not move the page image'))->toBeTrue();

	// Nothing was written for the failed page: the legacy keys and files are
	// still there for the next run, and the card did not take a description it
	// could not pair with an image.
	$stored = json_decode((string)file_get_contents(objectPath('builder-pages', 'about')), true);
	expect($stored['description'] ?? '')->toBe('Legacy meta description')
		->and($stored['image']['name'] ?? '')->toBe('test-image.jpg')
		->and($stored['seo']['description'] ?? '')->toBe('');
	expect(is_file($files . '/image/test-image.jpg'))->toBeTrue();

	// The sibling still migrated, and the batch's single index rebuild still
	// happened — the throw comes after both.
	$terms = $this->container->get(ObjectFetcher::class)->fetchObject('builder-pages', 'terms')->toArray();
	expect($terms['seo']['description'] ?? '')->toBe('Legacy meta description')
		->and($terms['seo']['image']['name'] ?? '')->toBe('test-image.jpg');

	$rows = $this->container->get(IndexReader::class)->fetchIndex('builder-pages')->objects
		->filter(fn (array $row): bool => ($row['id'] ?? '') === 'terms')
		->values();
	expect($rows[0]['seo']['description'] ?? '')->toBe('Legacy meta description');
});

it('is left unrecorded by the runner when it throws, so the next run retries', function (): void {
	($this->legacyPage)('about');

	$files = objectFilesPath('builder-pages', 'about');
	mkdir($files . '/seo', 0755, true);
	file_put_contents($files . '/seo/image', 'not a directory');

	$state  = $this->container->get(MigrationStateRepository::class);
	$runner = new MigrationRunner([($this->migration)()], $state, new NullLogger());
	$runner->runPending();

	expect($state->hasRun('builder-page-seo-fields'))->toBeFalse();

	// Clear the obstruction: the retry finds the page still un-migrated and
	// finishes the job, and NOW the ledger records it.
	unlink($files . '/seo/image');
	$runner->runPending();

	expect($state->hasRun('builder-page-seo-fields'))->toBeTrue();
	expect(is_file($files . '/seo/image/test-image.jpg'))->toBeTrue();
});

it('preserves the page updated date', function (): void {
	($this->legacyPage)('about');

	$fetcher = $this->container->get(ObjectFetcher::class);
	$before  = $fetcher->fetchObject('builder-pages', 'about')->toArray()['updated'] ?? '';
	expect($before)->not->toBe('');

	expect(($this->migration)()->run())->toBe(1);

	expect($fetcher->fetchObject('builder-pages', 'about')->toArray()['updated'] ?? '')->toBe($before);
});

it('rebuilds the index once so the row carries the migrated card', function (): void {
	($this->legacyPage)('about');

	expect(($this->migration)()->run())->toBe(1);

	$rows = $this->container->get(IndexReader::class)->fetchIndex('builder-pages')->objects
		->filter(fn (array $row): bool => ($row['id'] ?? '') === 'about')
		->values();

	expect($rows)->toHaveCount(1)
		->and($rows[0]['seo']['description'] ?? '')->toBe('Legacy meta description');
});
