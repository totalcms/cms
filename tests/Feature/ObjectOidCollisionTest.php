<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Service\OidAllocator;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/*
 * ${oid} used to be the collection's count plus one, read before the save,
 * with the count bumped only after the object was written. Two creates that
 * overlapped got the same number. Now a create claims its number under the
 * metadata lock before it generates the id.
 */

function oidNotesDir(): string
{
	return collectionPath('oidnotes');
}

function oidNotesCount(): int
{
	return (int)json_decode((string)file_get_contents(oidNotesDir() . '.meta.json'), true)['count'];
}

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	if (is_dir(oidNotesDir())) {
		exec('/bin/rm -rf ' . escapeshellarg(oidNotesDir()));
	}

	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'oidnote',
		'type'       => 'object',
		'properties' => [
			'id'    => ['type' => 'string', 'field' => 'id', 'settings' => ['autogen' => '${oid}']],
			'title' => ['type' => 'string', 'field' => 'text'],
			'code'  => ['type' => 'string', 'field' => 'text', 'settings' => ['autogen' => 'N-${oid}']],
		],
		'index' => ['id', 'title'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'oidnotes', 'name' => 'Oid Notes', 'schema' => 'oidnote']);
});

afterEach(function (): void {
	if (is_dir(oidNotesDir())) {
		exec('/bin/rm -rf ' . escapeshellarg(oidNotesDir()));
	}
});

test('a new collection numbers its first objects 1, 2, 3', function (): void {
	$saver = $this->app->getContainer()->get(ObjectSaver::class);

	$ids = array_map(fn (string $title): string => $saver->saveObject('oidnotes', ['title' => $title])->id, ['A', 'B', 'C']);

	expect($ids)->toBe(['1', '2', '3']);
	expect(json_decode((string)file_get_contents(oidNotesDir() . '.meta.json'), true))
		->count->toBe(3)
		->totalObjects->toBe(3);
});

test('creates get consecutive numbers and every autogen field shares the number', function (): void {
	$saver = $this->app->getContainer()->get(ObjectSaver::class);
	$saver->saveObject('oidnotes', ['title' => 'First']);
	$next = oidNotesCount() + 1;

	$objects = array_map(fn (string $title) => $saver->saveObject('oidnotes', ['title' => $title]), ['A', 'B', 'C']);

	expect(array_map(fn ($o) => $o->id, $objects))->toBe([(string)$next, (string)($next + 1), (string)($next + 2)]);
	expect($objects[2]->toArray()['code'])->toBe('N-' . ($next + 2));
	expect(oidNotesCount())->toBe($next + 2);
});

test('a create that starts while another is still being written gets the next number', function (): void {
	$first = $this->app->getContainer();

	// A second request with its own container, as a separate PHP process would have.
	$builder = new ContainerBuilder();
	$builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
	$second = $builder->build();

	[$firstNumber, $secondId] = $first->get(OidAllocator::class)->creating('oidnotes', fn (): array => [
		$first->get(OidAllocator::class)->next('oidnotes'),
		$second->get(ObjectSaver::class)->saveObject('oidnotes', ['title' => 'Second'])->id,
	]);

	expect($secondId)->toBe((string)($firstNumber + 1));
});

test('reading and updating an object do not use up numbers', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('oidnotes', ['title' => 'A']);
	$before = oidNotesCount();

	$container->get(ObjectUpdater::class)->updateObject('oidnotes', '1', ['id' => '1', 'title' => 'A2', 'code' => '']);

	expect(oidNotesCount())->toBe($before);
});

test('a submitted id that is taken fails and leaves the file alone', function (): void {
	$theirs = (string)json_encode(['id' => 'taken', 'title' => 'Theirs']);
	file_put_contents(oidNotesDir() . 'taken.json', $theirs);
	$meta = (string)file_get_contents(oidNotesDir() . '.meta.json');

	expect(fn () => $this->app->getContainer()->get(ObjectSaver::class)->saveObject('oidnotes', ['id' => 'taken', 'title' => 'Mine', 'code' => 'N-own']))
		->toThrow(DomainException::class, 'Object with id taken already exists in oidnotes');
	expect(file_get_contents(oidNotesDir() . 'taken.json'))->toBe($theirs);
	// It needed no number, so a refused create changes nothing at all.
	expect(file_get_contents(oidNotesDir() . '.meta.json'))->toBe($meta);
});

test('a create with a submitted id still advances the count once it is written', function (): void {
	$saver = $this->app->getContainer()->get(ObjectSaver::class);
	$saver->saveObject('oidnotes', ['title' => 'A']);
	$before = oidNotesCount();

	$saver->saveObject('oidnotes', ['id' => 'named', 'title' => 'Named', 'code' => 'N-own']);

	expect(oidNotesCount())->toBe($before + 1);
	expect($saver->saveObject('oidnotes', ['title' => 'Next'])->id)->toBe((string)($before + 2));
});

test('a create waits for another process that holds the collection create lock', function (): void {
	$lock  = oidNotesDir() . '.create.lock';
	$code  = sprintf('$h = fopen(%s, "c"); flock($h, LOCK_EX); echo "locked\n"; usleep(800000);', var_export($lock, true));
	$child = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
	expect(trim((string)fgets($pipes[1])))->toBe('locked');

	$saver = $this->app->getContainer()->get(ObjectSaver::class);
	$start = hrtime(true);
	$saver->saveObject('oidnotes', ['title' => 'Waited']);
	$waited = (hrtime(true) - $start) / 1e9;

	fclose($pipes[1]);
	proc_close($child);
	expect($waited)->toBeGreaterThan(0.5);
});
