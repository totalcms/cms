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

it('offers seedable collections to the Sync Manager', function (string $id): void {
	expect(SyncableCollections::seedableInUi($id))->toBeTrue();
})->with(['blog', 'text', 'my-products']);

it('withholds auth from the Sync Manager even though the CLI allows it', function (): void {
	// Password hashes never travel, so a seeded user arrives as an account
	// nobody can sign into. Deliberate as `--objects=auth`; a trap as a
	// checkbox sitting next to Blog.
	expect(SyncableCollections::seedable('auth'))->toBeTrue();
	expect(SyncableCollections::seedableInUi('auth'))->toBeFalse();
});

it('withholds everything the seed carve-outs already refuse', function (string $id): void {
	expect(SyncableCollections::seedableInUi($id))->toBeFalse();
})->with(['playground', 'image', 'gallery', 'file', 'depot', 'builder-pages', 'automations']);
