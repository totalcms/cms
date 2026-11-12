<?php

declare(strict_types=1);

use TotalCMS\Domain\OAuth\Repository\OAuthStateStore;

beforeEach(function (): void {
	$this->dir   = sys_get_temp_dir() . '/oauth-state-test-' . uniqid('', true);
	$this->store = new OAuthStateStore($this->dir);
});

afterEach(function (): void {
	recursiveDelete($this->dir);
});

/** Rewrite every entry in $bucket so it expired a minute ago. */
function oauthStateExpire(string $dir, string $bucket): void
{
	foreach (glob($dir . '/' . $bucket . '/*.json') ?: [] as $path) {
		$entry            = json_decode((string)file_get_contents($path), true);
		$entry['expires'] = time() - 60;
		file_put_contents($path, (string)json_encode($entry));
	}
}

test('a value reads back, from another instance too', function (): void {
	$this->store->put('replay', 'hash-1', ['client_id' => 'c', 'user_id' => 'u'], 60);

	expect($this->store->get('replay', 'hash-1'))->toBe(['client_id' => 'c', 'user_id' => 'u'])
		->and((new OAuthStateStore($this->dir))->get('replay', 'hash-1'))->toBe(['client_id' => 'c', 'user_id' => 'u']);
});

test('a missing key reads as null', function (): void {
	expect($this->store->get('revoked', 'never-written'))->toBeNull();
});

test('buckets are separate', function (): void {
	$this->store->put('revoked', 'same-key', true, 60);

	expect($this->store->get('authcodes', 'same-key'))->toBeNull();
});

test('an expired entry reads as null and is removed', function (): void {
	$this->store->put('authcodes', 'code-1', true, 60);
	oauthStateExpire($this->dir, 'authcodes');

	expect($this->store->get('authcodes', 'code-1'))->toBeNull()
		->and(glob($this->dir . '/authcodes/*.json'))->toBe([]);
});

test('delete removes an entry, and deleting a missing one is a no-op', function (): void {
	$this->store->put('authcodes', 'code-2', true, 60);
	$this->store->delete('authcodes', 'code-2');
	$this->store->delete('authcodes', 'code-2');

	expect($this->store->get('authcodes', 'code-2'))->toBeNull();
});

test('no code or token id appears in a file name', function (): void {
	$this->store->put('authcodes', 'secret-code-value', true, 60);

	$names = array_map('basename', glob($this->dir . '/authcodes/*') ?: []);
	expect($names)->toBe([hash('sha256', 'secret-code-value') . '.json']);
});

test('pruneExpired removes expired and unreadable entries and keeps live ones', function (): void {
	$this->store->put('revoked', 'live', true, 3600);
	$this->store->put('replay', 'old', ['client_id' => 'c', 'user_id' => 'u'], 60);
	oauthStateExpire($this->dir, 'replay');
	file_put_contents($this->dir . '/revoked/' . str_repeat('0', 64) . '.json', 'not json');

	expect($this->store->pruneExpired())->toBe(2)
		->and($this->store->get('revoked', 'live'))->toBeTrue()
		->and(glob($this->dir . '/*/*.json'))->toHaveCount(1);
});

test('pruneExpired on a store never written to removes nothing', function (): void {
	expect($this->store->pruneExpired())->toBe(0);
});

test('a bucket name that could leave the folder is refused', function (string $bucket): void {
	expect(fn () => $this->store->put($bucket, 'k', true, 60))->toThrow(InvalidArgumentException::class);
})->with(['../escape', 'a/b', '', 'Upper']);
