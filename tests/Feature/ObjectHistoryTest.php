<?php

declare(strict_types=1);

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;
use TotalCMS\Domain\Backup\Service\ObjectHistory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

/*
 * An object's history as the Backups utility reads it, through the real
 * container: real snapshots written by real saves, compared with the real
 * schema.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();

	$this->c->get(SchemaSaver::class)->saveSchema(['id' => 'articles', 'type' => 'object', 'properties' => [
		'id'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'   => ['type' => 'string', 'field' => 'text', 'label' => 'Title'],
		'price'   => ['type' => 'number', 'field' => 'number', 'label' => 'Price'],
		'cover'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'label' => 'Cover'],
		'secret'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/password.json', 'field' => 'password', 'label' => 'Password'],
	], 'index' => ['id', 'title']]);
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'articles', 'name' => 'Articles', 'schema' => 'articles']);
	$this->c->get(ObjectSaver::class)->saveObject('articles', ['id' => 'one', 'title' => 'Autumn Sale', 'price' => 49, 'secret' => 'first password']);

	$this->history = $this->c->get(ObjectHistory::class);

	$this->update = function (array $fields): void {
		$record = $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('articles', 'one')->toArray();
		$this->c->get(ObjectUpdater::class)->updateObject('articles', 'one', $fields + $record);
	};

	$this->upload = function (string $name, string $fixture = 'test-image.jpg'): void {
		$dir = tcmsTestTempDir('object-history');
		is_dir($dir) || mkdir($dir, 0755, true);
		copy(testData($fixture), "{$dir}/{$name}");
		$this->c->get(SaverFactory::class)->generateSaverService('articles', 'cover', 'one')->save('articles', 'one', 'cover', "{$dir}/{$name}");
	};
});

test('an object that was never changed has no history', function (): void {
	expect($this->history->entries('articles', 'one'))->toBe([])
		->and($this->history->isLive('articles', 'one'))->toBeTrue()
		->and($this->history->entries('articles', 'nobody'))->toBe([]);
});

test('each entry says which fields the change touched, newest first', function (): void {
	($this->update)(['title' => 'Winter Sale']);
	($this->update)(['price' => 39, 'title' => 'Winter Sale!']);

	$entries = $this->history->entries('articles', 'one');

	expect(array_column($entries, 'changed'))->toBe([['Title', 'Price'], ['Title']])
		->and(array_column($entries, 'event'))->toBe(['changed', 'changed'])
		->and($entries[0]['file'])->toMatch('/^one-\d{8}-\d{6}(-\d+)?\.json$/')
		->and($entries[0]['at'])->toMatch('/^\d{4}-\d\d-\d\dT/');
});

test('opening an entry shows each field before and after that change', function (): void {
	($this->update)(['title' => 'Winter Sale']);
	($this->update)(['price' => 39]);

	$entries = $this->history->entries('articles', 'one');
	$older   = $this->history->changes('articles', 'one', $entries[1]['file']);
	$newer   = $this->history->changes('articles', 'one', $entries[0]['file']);

	expect(array_map(fn (array $c): string => "{$c['label']}: {$c['before']} → {$c['after']}", $older))->toBe(['Title: Autumn Sale → Winter Sale'])
		->and(array_map(fn (array $c): string => "{$c['label']}: {$c['before']} → {$c['after']}", $newer))->toBe(['Price: 49 → 39'])
		->and($this->history->changes('articles', 'one', 'one-19990101-000000.json'))->toBe([]);
});

test('a changed password is named and never shown', function (): void {
	($this->update)(['secret' => 'second password']);

	$entries = $this->history->entries('articles', 'one');
	$changes = $this->history->changes('articles', 'one', $entries[0]['file']);

	expect($entries[0]['changed'])->toBe(['Password'])
		->and($changes[0])->toMatchArray(['kind' => 'secret', 'before' => '', 'after' => ''])
		->and(json_encode([$entries, $changes]))->not->toContain('$2y$');
});

test('a replaced image says whether the old one can still be had', function (bool $kept, string $state): void {
	if ($kept) {
		$config          = $this->c->get(Config::class);
		$config->backups = ['keepImages' => 1] + $config->backups;
	}
	($this->upload)('hero.jpg');
	($this->upload)('banner.jpg', 'image-exif.jpg');

	$entries = $this->history->entries('articles', 'one');
	$change  = $this->history->changes('articles', 'one', $entries[0]['file'])[0];

	expect($change)->toMatchArray(['kind' => 'file', 'label' => 'Cover', 'before' => 'hero.jpg', 'after' => 'banner.jpg', 'beforeState' => $state]);
})->with([
	'kept'     => [true, 'kept'],
	'not kept' => [false, 'gone'],
]);

describe('preview', function (): void {
	test('says what a restore would change, from now to the snapshot', function (): void {
		($this->update)(['title' => 'Winter Sale']);
		($this->update)(['price' => 39]);
		$oldest = $this->history->entries('articles', 'one')[1]['file'];

		$preview = $this->history->preview('articles', 'one', $oldest);

		expect(array_map(fn (array $c): string => "{$c['label']}: {$c['before']} → {$c['after']}", $preview['changes']))->toBe(['Title: Winter Sale → Autumn Sale', 'Price: 39 → 49'])
			->and($preview)->toMatchArray(['live' => true, 'back' => [], 'lost' => [], 'passwordKept' => false]);
	});

	test('for one field, says only what that field would do', function (): void {
		($this->update)(['title' => 'Winter Sale', 'price' => 39]);
		$file = $this->history->entries('articles', 'one')[0]['file'];

		expect(array_column($this->history->preview('articles', 'one', $file, 'price')['changes'], 'label'))->toBe(['Price']);
	});

	test('names the files that would come back and those that cannot', function (bool $kept, array $back, array $lost): void {
		if ($kept) {
			$config          = $this->c->get(Config::class);
			$config->backups = ['keepImages' => 1] + $config->backups;
		}
		($this->upload)('hero.jpg');
		($this->upload)('banner.jpg', 'image-exif.jpg');
		$file = $this->history->entries('articles', 'one')[0]['file'];

		$preview = $this->history->preview('articles', 'one', $file);

		expect(array_column($preview['back'], 'name'))->toBe($back)
			->and(array_column($preview['lost'], 'name'))->toBe($lost);
	})->with([
		'kept'     => [true, ['hero.jpg'], []],
		'not kept' => [false, [], ['hero.jpg']],
	]);

	test('a password the snapshot would change is left as it is now, and the preview says so', function (): void {
		($this->update)(['secret' => 'second password', 'title' => 'Winter Sale']);
		$file = $this->history->entries('articles', 'one')[0]['file'];

		$preview = $this->history->preview('articles', 'one', $file);

		expect($preview['passwordKept'])->toBeTrue()
			->and(array_column($preview['changes'], 'label'))->toBe(['Title']);
	});

	test('a snapshot that cannot be read has no preview', function (): void {
		expect($this->history->preview('articles', 'one', 'one-19990101-000000.json'))->toBeNull();
	});
});

describe('a deleted object', function (): void {
	beforeEach(function (): void {
		($this->update)(['title' => 'Winter Sale']);
		$this->c->get(ObjectRemover::class)->deleteObject('articles', 'one');
	});

	test('keeps its history, with its final state on top', function (): void {
		$entries = $this->history->entries('articles', 'one');

		expect($this->history->isLive('articles', 'one'))->toBeFalse()
			->and(array_column($entries, 'event'))->toBe(['deleted', 'changed'])
			->and($entries[0]['changed'])->toBe([])
			// The change before the delete still reads against the state that followed it.
			->and($entries[1]['changed'])->toBe(['Title'])
			->and($this->history->changes('articles', 'one', $entries[0]['file']))->toBe([]);
	});

	test('is listed among the collection\'s deleted objects, with a name from its final state', function (): void {
		$this->c->get(ObjectSaver::class)->saveObject('articles', ['id' => 'still-here', 'title' => 'Live']);
		$this->c->get(ObjectUpdater::class)->updateObject('articles', 'still-here', ['id' => 'still-here', 'title' => 'Live, edited']);

		$deleted = $this->history->deleted('articles');

		expect(array_column($deleted, 'id'))->toBe(['one'])
			->and($deleted[0]['snapshots'])->toBe(2)
			->and($this->history->describe('articles', 'one', $deleted[0]['file'], $deleted[0]['at'])['title'])->toBe('Winter Sale')
			->and($this->history->deleted('nothing-here'))->toBe([]);
	});

	test('previews as coming back whole', function (): void {
		$file = $this->history->entries('articles', 'one')[0]['file'];

		expect($this->history->preview('articles', 'one', $file))->toMatchArray(['live' => false, 'changes' => []]);
	});
});

test('who made a change comes from the activity log entry of the same moment', function (): void {
	($this->update)(['title' => 'Winter Sale']);
	$at = $this->history->entries('articles', 'one')[0]['at'];

	// Whatever the save itself logged, a signed-in editor's entry at that moment is the one shown.
	$log = $this->c->get(ActivityRepository::class);
	$log->prune(PHP_INT_MAX);
	$log->record(new ActivityEntry(event: CoreEvent::OBJECT_UPDATED, actorKind: 'user', userId: 'joe', authCollection: 'auth', collection: 'articles', objectId: 'one', createdAt: (int)strtotime($at)));
	$log->record(new ActivityEntry(event: CoreEvent::OBJECT_UPDATED, actorKind: 'user', userId: 'sam', authCollection: 'auth', collection: 'articles', objectId: 'one', createdAt: (int)strtotime($at) - 3600));
	$log->record(new ActivityEntry(event: CoreEvent::OBJECT_UPDATED, actorKind: 'user', userId: 'amy', authCollection: 'auth', collection: 'articles', objectId: 'two', createdAt: (int)strtotime($at)));

	expect($this->history->entries('articles', 'one')[0]['who'])->toBe('auth/joe');

	$log->prune(PHP_INT_MAX);

	expect($this->history->entries('articles', 'one')[0]['who'])->toBe('');
});
