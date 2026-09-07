<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

/**
 * `format` is chosen at creation and then fixed: the files on disk and the
 * setting must never disagree, so a change has to go through
 * `tcms collection:convert`.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	signInAs($this->app, 'admin-user-test-com', 'auth');
});

$create = fn (array $extra = []): array => array_merge([
	'id'     => 'notes',
	'name'   => 'Notes',
	'schema' => 'blog',
], $extra);

it('defaults to json and persists markdown when asked', function () use ($create): void {
	postJson('/api/collections', $create())->assertOk();
	expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('notes')->format)->toBe('json');

	postJson('/api/collections', $create(['id' => 'docs', 'format' => 'markdown']))->assertOk();
	$docs = $this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('docs');
	expect($docs->format)->toBe('markdown')->and($docs->isMarkdown())->toBeTrue();
	expect(json_decode((string)file_get_contents(collectionPath('docs') . '.meta.json'), true)['format'])->toBe('markdown');
});

it('rejects an unknown format', function () use ($create): void {
	postJson('/api/collections', $create(['format' => 'toml']))->assertStatus(400);
});

it('refuses to change the format after creation and keeps it when omitted', function () use ($create): void {
	postJson('/api/collections', $create(['format' => 'markdown']))->assertOk();

	putJson('/api/collections/notes', $create(['format' => 'json']))->assertStatus(400);

	putJson('/api/collections/notes', $create(['name' => 'Renamed']))->assertOk();
	$notes = $this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('notes');
	expect($notes->name)->toBe('Renamed')->and($notes->format)->toBe('markdown');
});

it('is a no-op when the posted format is the current one under a different case', function () use ($create): void {
	// 'JSON' on a json collection is the same setting, not an attempted
	// change — a form re-posting its own (un-lowercased) value must not be
	// refused as though it were switching formats.
	postJson('/api/collections', $create())->assertOk();

	putJson('/api/collections/notes', $create(['format' => 'JSON']))->assertOk();
	expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('notes')->format)->toBe('json');
});
