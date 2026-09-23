<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Import\DeckItemImporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

// Merging imported items into a deck — id resolution, skip-existing unless
// updating, one parent write under import suspension, import.updated — was
// duplicated between DeckCsvImporter and DeckJsonImporter, with the id
// sanitizer written out four times.
final class DeckItemImporterTest extends TestCase
{
	public function testItemIdsAreSlugsWithUnderscores(): void
	{
		$this->assertSame('hello_world', DeckItemImporter::sanitizeId('Hello World!'));
		$this->assertSame('cafe_menu', DeckItemImporter::sanitizeId('Café Menu'));
	}

	public function testResolvesAnItemIdFromTheItemThenTheAutogenPatternThenAUid(): void
	{
		$importer = new DeckItemImporter($this->createMock(ObjectFetcher::class), $this->createMock(ObjectUpdater::class), $this->createMock(SchemaFetcher::class), new EventDispatcher(new NullLogger()));

		$this->assertSame('my_item', $importer->resolveItemId(['id' => 'My Item', 'title' => 'T'], '${title}'));
		$this->assertSame('from_title', $importer->resolveItemId(['id' => '  ', 'title' => 'From Title'], '${title}'));
		$this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $importer->resolveItemId(['title' => 'x'], ''));
	}

	public function testMergesItemsIntoTheDeckWritesTheParentOnceAndFiresImportUpdated(): void
	{
		$deck       = new DeckData(['one' => ['id' => 'one', 'label' => 'Old', 'keep' => 'yes']]);
		$parent     = new ObjectData('w1', ['mydeck' => $deck]);
		$fetcher    = $this->createMock(ObjectFetcher::class);
		$fetcher->method('fetchObject')->willReturn($parent);
		$updater    = $this->createMock(ObjectUpdater::class);
		$updater->expects($this->once())->method('updateObject')
			->with('widgets', 'w1', $this->callback(function (array $data): bool {
				$this->assertSame('New', $data['mydeck']['one']['label']);
				$this->assertSame('yes', $data['mydeck']['one']['keep'], 'an update merges, it does not replace');
				$this->assertSame('two', $data['mydeck']['two']['id']);

				return true;
			}));
		$fired      = 0;
		$dispatcher = new EventDispatcher(new NullLogger());
		$dispatcher->listen(CoreEvent::IMPORT_UPDATED, function () use (&$fired): void {
			$fired++;
		});

		$importer = new DeckItemImporter($fetcher, $updater, $this->createMock(SchemaFetcher::class), $dispatcher);
		$count    = $importer->importItems('widgets', 'w1', 'mydeck', ['one' => ['label' => 'New'], 'two' => ['label' => 'Two']], update: true, logger: new NullLogger());

		$this->assertSame(2, $count);
		$this->assertSame(1, $fired);
	}

	public function testExistingItemsAreSkippedUnlessUpdating(): void
	{
		$deck    = new DeckData(['one' => ['id' => 'one', 'label' => 'Old']]);
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('fetchObject')->willReturn(new ObjectData('w1', ['mydeck' => $deck]));
		$updater = $this->createMock(ObjectUpdater::class);
		$updater->expects($this->never())->method('updateObject');

		$importer = new DeckItemImporter($fetcher, $updater, $this->createMock(SchemaFetcher::class), new EventDispatcher(new NullLogger()));

		$this->assertSame(0, $importer->importItems('widgets', 'w1', 'mydeck', ['one' => ['label' => 'New']], update: false, logger: new NullLogger()));
	}

	public function testANonDeckPropertyIsRefused(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('fetchObject')->willReturn(new ObjectData('w1', ['title' => new StringData('plain')]));
		$importer = new DeckItemImporter($fetcher, $this->createMock(ObjectUpdater::class), $this->createMock(SchemaFetcher::class), new EventDispatcher(new NullLogger()));

		$this->expectException(\InvalidArgumentException::class);
		$importer->importItems('widgets', 'w1', 'title', ['x' => []], update: false, logger: new NullLogger());
	}
}
