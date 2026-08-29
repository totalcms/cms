<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use TotalCMS\Domain\Sync\Data\SyncableCollections;

it('maps every feature flag onto an allowlisted collection', function (): void {
	expect(array_values(SyncableCollections::FEATURE_FLAGS))
		->toEqualCanonicalizing(SyncableCollections::IDS);
});

it('refuses to seed binary-only collections', function (string $id): void {
	expect(SyncableCollections::seedable($id))->toBeFalse();
})->with(['image', 'gallery', 'file', 'depot']);

it('refuses to seed the playground scratchpad', function (): void {
	expect(SyncableCollections::seedable('playground'))->toBeFalse();
});

it('refuses to seed collections that already have a dedicated flag', function (string $id): void {
	expect(SyncableCollections::seedable($id))->toBeFalse();
})->with(SyncableCollections::IDS);

it('seeds content, reserved and custom collections', function (string $id): void {
	expect(SyncableCollections::seedable($id))->toBeTrue();
})->with(['blog', 'auth', 'text', 'my-products']);

it('names the flag that owns a collection', function (): void {
	expect(SyncableCollections::flagFor('builder-pages'))->toBe('pages');
	expect(SyncableCollections::flagFor('mcp-prompt'))->toBe('mcp-prompts');
	expect(SyncableCollections::flagFor('blog'))->toBeNull();
});
