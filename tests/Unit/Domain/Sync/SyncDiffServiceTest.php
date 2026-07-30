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

	test('different content is DIFFERS with the later side hinted newer', function (): void {
		$diff = $this->service->diff(
			['schemas' => [['id' => 'blog', 'properties' => ['title' => [], 'tags' => []], 'updated' => '2026-07-29T10:00:00+00:00']]],
			['schemas' => [['id' => 'blog', 'properties' => ['title' => []], 'updated' => '2026-07-01T10:00:00+00:00']]],
		);

		expect($diff['schemas']['blog']['status'])->toBe(SyncDiffService::DIFFERS);
		expect($diff['schemas']['blog']['newer'])->toBe('local');
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
	});
});
