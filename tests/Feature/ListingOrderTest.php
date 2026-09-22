<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * Collections and schemas are read from directories, and directory order is
 * the filesystem's: alphabetical on APFS, hash order on ext4. Two CI failures
 * that never reproduced on a Mac came from exactly that — a grouped
 * collection list and a form snapshot listing schemas. Both listers sort by
 * id now, so a Mac and Linux agree.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

test('collections list in id order whatever the directory order', function (): void {
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'note', 'type' => 'object', 'properties' => ['id' => ['type' => 'string', 'field' => 'id'], 'title' => ['type' => 'string', 'field' => 'text']], 'required' => ['id'], 'index' => ['id']]);
	foreach (['zeta', 'alpha', 'mid', 'beta-2', 'beta'] as $id) {
		$c->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => $id, 'schema' => 'note']);
	}

	$ids  = array_map(static fn ($col) => $col->id, $c->get(CollectionLister::class)->listAllCollections());
	$mine = array_values(array_filter($ids, static fn (string $id): bool => in_array($id, ['zeta', 'alpha', 'mid', 'beta-2', 'beta'], true)));

	expect($mine)->toBe(['alpha', 'beta', 'beta-2', 'mid', 'zeta']);
});

test('schemas list in id order whatever the directory order', function (): void {
	$c = $this->app->getContainer();
	// `blog-legacy` is reserved; the same shape with a custom prefix.
	foreach (['widgets', 'widget-card', 'zz-blog-legacy', 'zz-blog'] as $id) {
		$c->get(SchemaSaver::class)->saveSchema(['id' => $id, 'type' => 'object', 'properties' => ['id' => ['type' => 'string', 'field' => 'id']], 'required' => ['id']]);
	}

	$ids    = array_map(static fn ($s) => $s->id, $c->get(SchemaLister::class)->listAllSchemas());
	$sorted = $ids;
	usort($sorted, static fn (string $a, string $b): int => strnatcmp(mb_strtolower($a), mb_strtolower($b)));

	expect($ids)->toBe($sorted)
		->and(array_search('zz-blog', $ids, true))->toBeLessThan(array_search('zz-blog-legacy', $ids, true))
		->and(array_search('widget-card', $ids, true))->toBeLessThan(array_search('widgets', $ids, true));
});
