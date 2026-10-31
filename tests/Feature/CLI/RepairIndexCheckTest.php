<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Maintenance\RepairIndexCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\TotalCMS;

/*
 * repair:index --check reports how a collection's index and files disagree,
 * and changes nothing — the point is to see the damage before a rebuild
 * paves over it.
 */

function removeCheckCollections(): void
{
	foreach (['checknotes', 'checkdocs'] as $collection) {
		if (is_dir(collectionPath($collection))) {
			exec('/bin/rm -rf ' . escapeshellarg(collectionPath($collection)));
		}
	}
}

afterEach(function (): void {
	removeCheckCollections();
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	removeCheckCollections();

	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'checknote',
		'type'       => 'object',
		'properties' => [
			'id'    => ['type' => 'string', 'field' => 'id'],
			'title' => ['type' => 'string', 'field' => 'text'],
		],
		'index' => ['id', 'title'],
	]);
	$collections = $container->get(CollectionSaver::class);
	$collections->saveCollection(['id' => 'checknotes', 'name' => 'Check Notes', 'schema' => 'checknote']);
	$collections->saveCollection(['id' => 'checkdocs', 'name' => 'Check Docs', 'schema' => 'checknote', 'format' => 'markdown']);

	$saver = $container->get(ObjectSaver::class);
	foreach (['a', 'b', 'c'] as $id) {
		$saver->saveObject('checknotes', ['id' => $id, 'title' => strtoupper($id)]);
		$saver->saveObject('checkdocs', ['id' => $id, 'title' => strtoupper($id)]);
	}

	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('collectionFetcher')->willReturn($container->get(CollectionFetcher::class));
	$totalcms->method('container')->willReturn($container);
	$totalcms->method('indexBuilder')->willReturn($container->get(IndexBuilder::class));
	$totalcms->method('objectFetcher')->willReturn($container->get(ObjectFetcher::class));

	$app = new Application();
	$app->addCommand(new RepairIndexCommand($totalcms));
	$this->repair    = new CommandTester($app->find('repair:index'));
	$this->indexFile = collectionPath('checknotes') . '.index.json';
});

test('a rebuild reports the real count for a collection large enough to stream', function (): void {
	// Past 500 objects the index is streamed straight to disk, and the
	// rebuild used to report the empty IndexData it got back: "0 object(s)".
	for ($i = 0; $i < 600; $i++) {
		file_put_contents(collectionPath('checknotes') . "bulk-{$i}.json", (string)json_encode(['id' => "bulk-{$i}", 'title' => "Bulk {$i}"]));
	}

	$code = $this->repair->execute(['collection' => 'checknotes']);

	expect($code)->toBe(0);
	expect($this->repair->getDisplay())->toContain("Rebuilt index for 'checknotes' — 603 object(s).");
});

test('a consistent collection passes', function (): void {
	$code = $this->repair->execute(['collection' => 'checknotes', '--check' => true]);

	expect($code)->toBe(0);
	expect($this->repair->getDisplay())->toContain("'checknotes' is consistent — 3 object(s)");
});

test('a markdown collection is read the way core reads it', function (): void {
	$code = $this->repair->execute(['collection' => 'checkdocs', '--check' => true]);

	expect($code)->toBe(0, $this->repair->getDisplay());
});

test('reports a torn object file and leaves the index alone', function (): void {
	$before = file_get_contents($this->indexFile);
	file_put_contents(collectionPath('checknotes') . 'b.json', '{"id":"b","tit');

	$code = $this->repair->execute(['collection' => 'checknotes', '--check' => true]);

	expect($code)->toBe(1);
	expect($this->repair->getDisplay())->toContain('Unreadable object: b');
	expect(file_get_contents($this->indexFile))->toBe($before);
});

test('reports index entries with no file and files missing from the index', function (): void {
	unlink(collectionPath('checknotes') . 'c.json');
	file_put_contents(collectionPath('checknotes') . 'd.json', json_encode(['id' => 'd', 'title' => 'D']));

	$code = $this->repair->execute(['collection' => 'checknotes', '--check' => true]);

	expect($code)->toBe(1);
	$display = $this->repair->getDisplay();
	expect($display)->toContain('In the index but no file: c');
	expect($display)->toContain('File not in the index: d');
});

test('reports an unreadable index', function (): void {
	file_put_contents($this->indexFile, '{"objects":[');

	$code = $this->repair->execute(['collection' => 'checknotes', '--check' => true]);

	expect($code)->toBe(1);
	expect($this->repair->getDisplay())->toContain('The index file is unreadable');
});

test('reports a missing index', function (): void {
	unlink($this->indexFile);

	$code = $this->repair->execute(['collection' => 'checknotes', '--check' => true]);

	expect($code)->toBe(1);
	expect($this->repair->getDisplay())->toContain('There is no index file');
});

test('--json gives the problems per collection', function (): void {
	unlink(collectionPath('checknotes') . 'c.json');

	$code = $this->repair->execute(['collection' => 'checknotes', '--check' => true, '--json' => true]);

	expect($code)->toBe(1);
	$data = json_decode($this->repair->getDisplay(), true);
	expect($data['status'])->toBe('problems');
	expect($data['collections']['checknotes']['objects'])->toBe(2);
	expect($data['collections']['checknotes']['indexed'])->toBe(3);
	expect($data['collections']['checknotes']['missingFiles'])->toBe(['c']);
	expect($data['collections']['checknotes']['problems'])->toBe([
		'In the index but no file: c',
		"The collection's object count says 3; there are 2 on disk",
	]);
});
