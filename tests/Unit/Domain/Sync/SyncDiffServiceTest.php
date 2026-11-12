<?php

declare(strict_types=1);

use TotalCMS\Domain\Sync\Service\SyncDiffService;

// SyncDiffService separates two signals on purpose: content hashing decides
// WHETHER two copies differ (timestamps excluded — they record when a write
// happened, not what it wrote), and the `updated` timestamps decide WHICH
// side is newer, as a hint only.

describe('SyncDiffService', function (): void {
	beforeEach(function (): void {
		$this->service = new SyncDiffService();
	});

	test('identical content with different timestamps is SAME', function (): void {
		// The pre-rc.15 world restamped `updated` on every sync, so identical
		// copies routinely carry different stamps. That must not read as drift.
		$diff = $this->service->diff(
			['schemas' => [['id' => 'blog', 'properties' => ['title' => []], 'updated' => '2026-07-01T10:00:00+00:00']]],
			['schemas' => [['id' => 'blog', 'properties' => ['title' => []], 'updated' => '2026-07-20T10:00:00+00:00']]],
		);

		expect($diff['schemas']['blog']['status'])->toBe(SyncDiffService::SAME);
		expect($diff['schemas']['blog']['newer'])->toBeNull();
	});

	test('a Data View rebuilt on one side only is SAME', function (): void {
		// Every scheduled rebuild writes lastBuilt (and bumps updated) on the
		// site that ran it. That is the site's own state, not an edit.
		$view = fn (string $built, string $updated): array => ['objects' => [[
			'collection' => 'dataviews',
			'id'         => 'posts',
			'data'       => ['name' => 'Posts', 'definition' => '{% set data = [] %}', 'lastBuilt' => $built, 'lastError' => '', 'updated' => $updated],
		]]];

		$diff = $this->service->diff($view('2026-11-01T09:00:00-08:00', '2026-11-01T09:00:00-08:00'), $view('2026-11-12T03:00:00-08:00', '2026-11-12T03:00:00-08:00'));

		expect($diff['objects']['dataviews/posts']['status'])->toBe(SyncDiffService::SAME)
			->and($diff['objects']['dataviews/posts']['newer'])->toBeNull();
	});

	test('a field a site keeps for itself still differs in another collection', function (): void {
		$page = fn (string $built): array => ['objects' => [['collection' => 'builder-pages', 'id' => 'home', 'data' => ['lastBuilt' => $built]]]];

		expect($this->service->diff($page('a'), $page('b'))['objects']['builder-pages/home']['status'])->toBe(SyncDiffService::DIFFERS);
	});

	test('different content is DIFFERS with the later side hinted newer', function (): void {
		$diff = $this->service->diff(
			['schemas' => [['id' => 'blog', 'properties' => ['title' => [], 'tags' => []], 'updated' => '2026-07-29T10:00:00+00:00']]],
			['schemas' => [['id' => 'blog', 'properties' => ['title' => []], 'updated' => '2026-07-01T10:00:00+00:00']]],
		);

		expect($diff['schemas']['blog']['status'])->toBe(SyncDiffService::DIFFERS);
		expect($diff['schemas']['blog']['newer'])->toBe('local');
	});

	test('an empty block and an empty default compare SAME', function (): void {
		// The exporter sends absent-when-empty keys as `[]` (mirror semantics),
		// the receiving saver normalised that into `mcp: {tools: []}`, and the
		// next comparison hashed `[]` against `{tools: []}` — so a collection
		// read "differs" right after the push that made the two sides equal.
		// Empty means empty, whichever shape it takes.
		$diff = $this->service->diff(
			['collections' => ['custom' => [['id' => 'features', 'mcp' => [], 'sitemap' => ['enabled' => true]]]]],
			['collections' => ['custom' => [['id' => 'features', 'mcp' => ['tools' => []], 'sitemap' => ['enabled' => true]]]]],
		);
		expect($diff['collections']['features']['status'])->toBe(SyncDiffService::SAME);

		// Absent on one side, `[]` on the other: the same thing.
		$diff = $this->service->diff(
			['collections' => ['custom' => [['id' => 'features']]]],
			['collections' => ['custom' => [['id' => 'features', 'mcp' => [], 'properties' => []]]]],
		);
		expect($diff['collections']['features']['status'])->toBe(SyncDiffService::SAME);

		// A block with content still differs from an empty one.
		$diff = $this->service->diff(
			['collections' => ['custom' => [['id' => 'features', 'mcp' => []]]]],
			['collections' => ['custom' => [['id' => 'features', 'mcp' => ['access' => 'public', 'tools' => []]]]]],
		);
		expect($diff['collections']['features']['status'])->toBe(SyncDiffService::DIFFERS);
	});

	test('key order does not masquerade as a content difference', function (): void {
		$diff = $this->service->diff(
			['schemas' => [['id' => 'a', 'properties' => ['x' => ['type' => 'string', 'label' => 'X']]]]],
			['schemas' => [['properties' => ['x' => ['label' => 'X', 'type' => 'string']], 'id' => 'a']]],
		);

		expect($diff['schemas']['a']['status'])->toBe(SyncDiffService::SAME);
	});

	test('one-sided items report local-only and remote-only', function (): void {
		$diff = $this->service->diff(
			['schemas' => [['id' => 'mine']]],
			['schemas' => [['id' => 'theirs']]],
		);

		expect($diff['schemas']['mine']['status'])->toBe(SyncDiffService::LOCAL_ONLY);
		expect($diff['schemas']['theirs']['status'])->toBe(SyncDiffService::REMOTE_ONLY);
	});

	test('objects key by collection/id and compare inside data, excluding updated', function (): void {
		$diff = $this->service->diff(
			['objects' => [
				['collection' => 'builder-pages', 'id' => 'home', 'data' => ['id' => 'home', 'title' => 'Home', 'updated' => '2026-07-29T13:00:00+00:00']],
				['collection' => 'mailer', 'id' => 'home', 'data' => ['id' => 'home', 'subject' => 'Hi']],
			]],
			['objects' => [
				['collection' => 'builder-pages', 'id' => 'home', 'data' => ['id' => 'home', 'title' => 'Home', 'updated' => '2026-07-24T15:00:00+00:00']],
			]],
		);

		// Same content, different updated → SAME. Distinct collections don't collide on id.
		expect($diff['objects']['builder-pages/home']['status'])->toBe(SyncDiffService::SAME);
		expect($diff['objects']['mailer/home']['status'])->toBe(SyncDiffService::LOCAL_ONLY);
	});

	test('an undated side is hinted older than a dated side, but only when content differs', function (): void {
		// The undated copy was last written by a version that didn't maintain
		// the field — hinted older. Content still decides "differs" alone.
		$diff = $this->service->diff(
			['objects' => [['collection' => 'mailer', 'id' => 'a', 'data' => ['id' => 'a', 'subject' => 'new', 'updated' => '2026-07-29T13:00:00+00:00']]]],
			['objects' => [['collection' => 'mailer', 'id' => 'a', 'data' => ['id' => 'a', 'subject' => 'old']]]],
		);

		expect($diff['objects']['mailer/a']['status'])->toBe(SyncDiffService::DIFFERS);
		expect($diff['objects']['mailer/a']['newer'])->toBe('local');
		expect($diff['objects']['mailer/a']['remoteUpdated'])->toBeNull();
	});

	test('neither side dated gives no direction hint', function (): void {
		$diff = $this->service->diff(
			['templates' => [['id' => 'layout', 'template' => '<div>a</div>']]],
			['templates' => [['id' => 'layout', 'template' => '<div>b</div>']]],
		);

		expect($diff['templates']['layout']['status'])->toBe(SyncDiffService::DIFFERS);
		expect($diff['templates']['layout']['newer'])->toBeNull();
	});

	test('empty payloads diff to empty categories', function (): void {
		$diff = $this->service->diff([], []);

		expect($diff['schemas'])->toBe([]);
		expect($diff['templates'])->toBe([]);
		expect($diff['objects'])->toBe([]);
		expect($diff['collections'])->toBe([]);
	});

	test('collection settings diff spans custom and reserved entries by id', function (): void {
		$diff = $this->service->diff(
			['collections' => [
				'custom'    => [['id' => 'products', 'schema' => 'product', 'mcp' => ['access' => 'public'], 'updated' => '2026-07-30T10:00:00+00:00']],
				'reserved'  => [['id' => 'builder-pages', 'schema' => 'builder-page', 'mcp' => []]],
			]],
			['collections' => [
				'custom'    => [['id' => 'products', 'schema' => 'product', 'mcp' => ['access' => 'admin'], 'updated' => '2026-07-01T10:00:00+00:00']],
				'reserved'  => [['id' => 'builder-pages', 'schema' => 'builder-page', 'mcp' => []]],
			]],
		);

		expect($diff['collections']['products']['status'])->toBe(SyncDiffService::DIFFERS);
		expect($diff['collections']['products']['newer'])->toBe('local');
		expect($diff['collections']['builder-pages']['status'])->toBe(SyncDiffService::SAME);
	});

	test('identical collection settings with different updated stamps are SAME', function (): void {
		$diff = $this->service->diff(
			['collections' => ['custom' => [['id' => 'a', 'url' => '/a', 'updated' => '2026-07-30T10:00:00+00:00']]]],
			['collections' => ['custom' => [['id' => 'a', 'url' => '/a', 'updated' => '2026-07-01T10:00:00+00:00']]]],
		);

		expect($diff['collections']['a']['status'])->toBe(SyncDiffService::SAME);
	});

	test('a bare-string reserved entry participates in existence comparison', function (): void {
		// Starter payloads (and older remotes) carry reserved collections as
		// bare schema ids — they index as minimal entries so local-only /
		// remote-only still reads correctly.
		$diff = $this->service->diff(
			['collections' => ['reserved' => ['mailer']]],
			['collections' => []],
		);

		expect($diff['collections']['mailer']['status'])->toBe(SyncDiffService::LOCAL_ONLY);
	});
});
