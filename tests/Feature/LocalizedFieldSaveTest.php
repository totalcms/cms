<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * An object saved without a localized field gets the empty value `[]`, which
 * JSON-encodes as an empty array, not an object. The property schema must
 * accept it (as the deck schema does), or every save and import that leaves
 * the field out fails validation (security scan finding 3, 2026-10-30).
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'phrases', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'label' => ['$ref' => 'https://www.totalcms.co/schemas/properties/localizedtext.json', 'field' => 'localizedtext'],
		'body'  => ['$ref' => 'https://www.totalcms.co/schemas/properties/localizedtext.json', 'field' => 'localizedstyledtext'],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'phrases', 'name' => 'Phrases', 'schema' => 'phrases']);
	$this->saver   = $c->get(ObjectSaver::class);
	$this->updater = $c->get(ObjectUpdater::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
});

test('an object without its localized fields saves', function (): void {
	$this->saver->saveObject('phrases', ['id' => 'p1', 'title' => 'One']);

	expect($this->fetcher->fetchObjectFromDisk('phrases', 'p1')->toArray()['title'])->toBe('One');
});

test('an object with empty localized fields saves', function (): void {
	$this->saver->saveObject('phrases', ['id' => 'p1', 'title' => 'One', 'label' => [], 'body' => '']);

	expect($this->fetcher->fetchObjectFromDisk('phrases', 'p1')->toArray()['title'])->toBe('One');
});

test('updating an object that has no localized values saves', function (): void {
	$this->saver->saveObject('phrases', ['id' => 'p1', 'title' => 'One']);
	$this->updater->updateObject('phrases', 'p1', ['id' => 'p1', 'title' => 'Two']);

	expect($this->fetcher->fetchObjectFromDisk('phrases', 'p1')->toArray()['title'])->toBe('Two');
});

test('localized values still save and a non-locale key is still refused', function (): void {
	$this->saver->saveObject('phrases', ['id' => 'p1', 'title' => 'One', 'label' => ['en_US' => 'Hello', 'de' => 'Hallo']]);
	expect($this->fetcher->fetchObjectFromDisk('phrases', 'p1')->toArray()['label'])->toBe(['en_US' => 'Hello', 'de' => 'Hallo']);

	expect(fn () => $this->saver->saveObject('phrases', ['id' => 'p2', 'title' => 'Two', 'label' => ['not a locale' => 'x']]))->toThrow(Exception::class);
});
