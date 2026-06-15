<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Visualizer\Service\RelationshipAnalyzer;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c       = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);
	$cols    = $c->get(CollectionSaver::class);

	$schemas->saveSchema([
		'id'         => 'author', 'name' => 'Author', 'type' => 'object',
		'properties' => ['id' => ['type' => 'string', 'field' => 'id'], 'name' => ['type' => 'string', 'field' => 'text']],
	]);
	$schemas->saveSchema([
		'id'         => 'post', 'name' => 'Post', 'type' => 'object',
		'properties' => [
			'id'     => ['type' => 'string', 'field' => 'id'],
			'title'  => ['type' => 'string', 'field' => 'text'],
			// Relational (FK) → the `authors` collection.
			'writer' => ['type' => 'string', 'field' => 'select', 'settings' => ['relationalOptions' => ['collection' => 'authors', 'label' => 'name', 'value' => 'id']]],
		],
	]);
	$schemas->saveSchema([
		'id'         => 'page', 'name' => 'Page', 'type' => 'object',
		'properties' => [
			'id'   => ['type' => 'string', 'field' => 'id'],
			// Composition → a sub-schema with no backing collection.
			'body' => ['field' => 'deck', 'schemaref' => 'https://www.totalcms.co/schemas/block.json'],
		],
	]);
	$schemas->saveSchema([
		'id'          => 'special-post', 'name' => 'Special Post', 'type' => 'object',
		'inheritFrom' => ['post'],
		'properties'  => ['id' => ['type' => 'string', 'field' => 'id'], 'extra' => ['type' => 'string', 'field' => 'text']],
	]);

	// `loners` uses the author schema but nothing references it and it
	// references nothing — an unconnected collection.
	foreach ([['authors', 'author'], ['posts', 'post'], ['pages', 'page'], ['specials', 'special-post'], ['loners', 'author']] as [$id, $schema]) {
		$col         = new CollectionData();
		$col->id     = $id;
		$col->name   = ucfirst($id);
		$col->schema = $schema;
		$cols->saveCollection($col->toArray());
	}

	$this->analyzer = $c->get(RelationshipAnalyzer::class);
});

function hasEdge(array $edges, string $from, string $to, string $type): bool
{
	foreach ($edges as $e) {
		if ($e['from'] === $from && $e['to'] === $to && $e['type'] === $type) {
			return true;
		}
	}

	return false;
}

it('emits a relational edge for a relationalOptions property', function (): void {
	$graph = $this->analyzer->analyze();

	expect(hasEdge($graph['edges'], 'posts', 'authors', 'relational'))->toBeTrue();
	expect($graph['nodes']['posts']['kind'])->toBe('collection');
});

it('emits a composition edge to a secondary schema node when no collection uses the sub-schema', function (): void {
	$graph = $this->analyzer->analyze();

	expect(hasEdge($graph['edges'], 'pages', 'schema:block', 'composition'))->toBeTrue();
	expect($graph['nodes']['schema:block']['kind'])->toBe('schema');
});

it('redirects an inheritance edge onto the collection that uses the parent schema', function (): void {
	$graph = $this->analyzer->analyze();

	// special-post inheritFrom post; the `post` schema is used by the `posts`
	// collection, so the edge points specials -> posts.
	expect(hasEdge($graph['edges'], 'specials', 'posts', 'inheritance'))->toBeTrue();
});

it('includes a field list on collection nodes', function (): void {
	$graph = $this->analyzer->analyze();

	$fieldNames = array_column($graph['nodes']['posts']['fields'], 'name');
	expect($fieldNames)->toContain('title')->toContain('writer');
});

it('prunes unconnected collections', function (): void {
	$full   = $this->analyzer->analyze();
	$pruned = $this->analyzer->pruneIsolated($full);

	expect($full['nodes'])->toHaveKey('loners');
	expect($pruned['nodes'])->not->toHaveKey('loners');
	// Connected collections survive.
	expect($pruned['nodes'])->toHaveKey('posts')->toHaveKey('authors');
});

it('filters to a one-hop ego graph around the focus collection', function (): void {
	$graph = $this->analyzer->egoGraph($this->analyzer->analyze(), 'posts');

	$ids = array_keys($graph['nodes']);
	sort($ids);

	// posts + its neighbours (authors via relational, specials via inheritance).
	expect($ids)->toBe(['authors', 'posts', 'specials']);
	expect(hasEdge($graph['edges'], 'pages', 'schema:block', 'composition'))->toBeFalse();
});
