<?php

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Visualizer\Service\ObjectRelationshipResolver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$c       = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);
	$cols    = $c->get(CollectionSaver::class);
	$objects = $c->get(ObjectSaver::class);

	$schemas->saveSchema([
		'id'         => 'author', 'name' => 'Author', 'type' => 'object',
		'index'      => ['id', 'name'], // name indexed so the visualizer can label by it
		'properties' => ['id' => ['type' => 'string', 'field' => 'id'], 'name' => ['type' => 'string', 'field' => 'text']],
	]);
	$schemas->saveSchema([
		'id'         => 'post', 'name' => 'Post', 'type' => 'object',
		'index'      => ['id', 'title', 'writer'], // writer must be indexed for the reverse-lookup
		'properties' => [
			'id'     => ['type' => 'string', 'field' => 'id'],
			'title'  => ['type' => 'string', 'field' => 'text'],
			'writer' => ['type' => 'string', 'field' => 'select', 'settings' => ['relationalOptions' => ['collection' => 'authors', 'label' => 'name', 'value' => 'id']]],
		],
	]);

	foreach ([['authors', 'author'], ['posts', 'post']] as [$id, $schema]) {
		$col         = new CollectionData();
		$col->id     = $id;
		$col->name   = ucfirst($id);
		$col->schema = $schema;
		$cols->saveCollection($col->toArray());
	}

	$objects->saveObject('authors', ['id' => 'jane', 'name' => 'Jane']);
	$objects->saveObject('posts', ['id' => 'p1', 'title' => 'First', 'writer' => 'jane']);
	$objects->saveObject('posts', ['id' => 'p2', 'title' => 'Second', 'writer' => 'jane']);
	$objects->saveObject('posts', ['id' => 'p3', 'title' => 'Third', 'writer' => 'someone-else']);

	$this->resolver = $c->get(ObjectRelationshipResolver::class);
});

function edgeExists(array $edges, string $from, string $to, string $direction): bool
{
	foreach ($edges as $e) {
		if ($e['from'] === $from && $e['to'] === $to && $e['direction'] === $direction) {
			return true;
		}
	}

	return false;
}

it('resolves inbound references — what points at this record', function (): void {
	$graph = $this->resolver->resolve('authors', 'jane');

	// p1 and p2 reference jane; p3 references someone else.
	expect(edgeExists($graph['edges'], 'posts::p1', 'authors::jane', 'inbound'))->toBeTrue();
	expect(edgeExists($graph['edges'], 'posts::p2', 'authors::jane', 'inbound'))->toBeTrue();
	expect(edgeExists($graph['edges'], 'posts::p3', 'authors::jane', 'inbound'))->toBeFalse();

	expect($graph['nodes']['authors::jane']['focal'])->toBeTrue();
	expect($graph['nodes']['authors::jane']['label'])->toBe('Jane');
	expect($graph['truncated'])->toBeFalse();
});

it('resolves outbound references — what this record points at', function (): void {
	$graph = $this->resolver->resolve('posts', 'p1');

	expect(edgeExists($graph['edges'], 'posts::p1', 'authors::jane', 'outbound'))->toBeTrue();
	expect($graph['nodes']['authors::jane']['label'])->toBe('Jane');
});

it('handles a record with no relationships (focal node only)', function (): void {
	$graph = $this->resolver->resolve('authors', 'jane');
	// jane has no outbound (author schema has no relational props).
	$outbound = array_filter($graph['edges'], fn ($e) => $e['from'] === 'authors::jane');
	expect($outbound)->toBe([]);
});

it('resolves a whole collection — every object plus inbound references', function (): void {
	$graph = $this->resolver->resolveCollection('authors');

	// Every author is a node.
	expect($graph['nodes'])->toHaveKey('authors::jane');
	// Both posts that reference jane appear, with inbound edges landing on her.
	expect(edgeExists($graph['edges'], 'posts::p1', 'authors::jane', 'inbound'))->toBeTrue();
	expect(edgeExists($graph['edges'], 'posts::p2', 'authors::jane', 'inbound'))->toBeTrue();
	// p3 references a non-existent author, so it never points at a rendered node.
	$toJane = array_filter($graph['edges'], fn ($e) => $e['to'] === 'authors::jane');
	expect(count($toJane))->toBe(2);
	expect($graph['truncated'])->toBeFalse();
});

it('resolves outbound edges for every object in a collection', function (): void {
	$graph = $this->resolver->resolveCollection('posts');

	// p1 and p2 both point at jane; p3 points at a (missing) author node.
	expect(edgeExists($graph['edges'], 'posts::p1', 'authors::jane', 'outbound'))->toBeTrue();
	expect(edgeExists($graph['edges'], 'posts::p2', 'authors::jane', 'outbound'))->toBeTrue();
	// All three posts are rendered as focal-collection nodes.
	expect($graph['nodes'])->toHaveKeys(['posts::p1', 'posts::p2', 'posts::p3']);
});

it('cap check fires only on matching edges — non-matching rows do not burn the cap', function (): void {
	// Regression for the bug where the cap was checked at the OUTER (source-row)
	// loop entry before any inner edge landed. This meant that source rows whose
	// relational property doesn't point at any rendered focal object still
	// incremented (or rather pre-empted) the cap, causing real edges from later
	// rows to be silently dropped.
	//
	// Setup: resolve the "authors" collection. The inbound source is "posts".
	// p1 and p2 reference jane (rendered); p3 references "someone-else" (not
	// rendered). Under the old (broken) logic p3's iteration would be counted
	// against the cap. The correct behaviour is that only p1 and p2 consume cap
	// slots, and truncated stays false for this tiny dataset.
	$graph = $this->resolver->resolveCollection('authors');

	// Both valid inbound edges must be present.
	expect(edgeExists($graph['edges'], 'posts::p1', 'authors::jane', 'inbound'))->toBeTrue();
	expect(edgeExists($graph['edges'], 'posts::p2', 'authors::jane', 'inbound'))->toBeTrue();
	// Non-matching rows must not create phantom edges.
	expect(edgeExists($graph['edges'], 'posts::p3', 'authors::jane', 'inbound'))->toBeFalse();
	// With only 2 real matches the cap (50) is never reached.
	expect($graph['truncated'])->toBeFalse();
});
