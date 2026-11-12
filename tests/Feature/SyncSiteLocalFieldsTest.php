<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

// Some fields of a synced record are the site's own state, not something the
// developer authored: a Data View's last build, and the Site SEO record's
// IndexNow key. A push must not carry them, and must not wipe the
// destination's (SyncableCollections::siteLocalFields()).

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('dataviews');
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');

	$this->exporter = $c->get(JumpStartExporter::class);
	$this->importer = $c->get(JumpStartImporter::class);
	$this->fetcher  = $c->get(ObjectFetcher::class);
	$this->saver    = $c->get(ObjectSaver::class);
});

/** @return array<string,mixed>|null */
function syncedObject(JumpStartExporter $exporter, string $collection, string $id): ?array
{
	foreach ($exporter->exportSyncData(null, null, [$collection => null])->objects as $object) {
		if (($object['collection'] ?? '') === $collection && ($object['id'] ?? '') === $id) {
			return $object['data'];
		}
	}

	return null;
}

it('syncs the Site SEO record without its IndexNow key', function (): void {
	$this->saver->saveObject('seo-site', ['id' => 'seo-site', 'siteName' => 'Bistro', 'indexNowEnabled' => true, 'indexNowKey' => 'local-key-1234567890']);

	$data = syncedObject($this->exporter, 'seo-site', 'seo-site');

	expect($data)->not->toBeNull()
		->and($data['siteName'])->toBe('Bistro')
		->and($data)->not->toHaveKey('indexNowKey')
		->and($data)->not->toHaveKey('indexNowEnabled');
});

it('leaves a Data View\'s build state out of a push', function (): void {
	$this->saver->saveObject('dataviews', ['id' => 'posts', 'name' => 'Posts', 'definition' => '{% set data = [] %}', 'mcp' => ['access' => 'off'], 'lastBuilt' => '2026-11-01T09:00:00-08:00', 'lastError' => 'old']);

	$data = syncedObject($this->exporter, 'dataviews', 'posts');

	expect($data)->not->toBeNull()
		->and($data['name'])->toBe('Posts')
		->and($data)->not->toHaveKey('lastBuilt')
		->and($data)->not->toHaveKey('lastError');
});

it('keeps the destination\'s own state when a push updates the record', function (): void {
	$this->saver->saveObject('seo-site', ['id' => 'seo-site', 'siteName' => 'Old', 'indexNowEnabled' => true, 'indexNowKey' => 'live-key-1234567890']);
	$this->saver->saveObject('dataviews', ['id' => 'posts', 'name' => 'Posts', 'definition' => 'x', 'mcp' => ['access' => 'off'], 'lastBuilt' => '2026-11-12T03:00:00-08:00']);
	// Turning IndexNow on makes the key, so read the one the site holds.
	$liveKey = $this->fetcher->fetchObject('seo-site', 'seo-site')->toArray()['indexNowKey'];

	$this->importer->importFromDefinition(['objects' => [
		['collection' => 'seo-site', 'id' => 'seo-site', 'data' => ['id' => 'seo-site', 'siteName' => 'New']],
		['collection' => 'dataviews', 'id' => 'posts', 'data' => ['id' => 'posts', 'name' => 'Posts', 'definition' => 'y', 'mcp' => ['access' => 'off']]],
	]], upsert: true);

	$seo  = $this->fetcher->fetchObject('seo-site', 'seo-site')->toArray();
	$view = $this->fetcher->fetchObject('dataviews', 'posts')->toArray();

	expect($seo['siteName'])->toBe('New')
		->and($liveKey)->not->toBe('')
		->and($seo['indexNowKey'])->toBe($liveKey)
		->and($seo['indexNowEnabled'])->toBeTrue()
		->and($view['definition'])->toBe('y')
		->and($view['lastBuilt'])->toBe('2026-11-12T03:00:00-08:00');
});
