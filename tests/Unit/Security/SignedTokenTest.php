<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\SignedToken;

/*
 * HMAC tokens this site makes and checks itself: unsubscribe links, order
 * links, upload grants. A token is bound to the purpose it was minted for,
 * may carry an expiry, and is signed with a key derived from the site key.
 */

function signedToken(?string $key = 'site-key-one', int $now = 1_800_000_000): SignedToken
{
	return new SignedToken(static fn (): ?string => $key, static fn (): int => $now);
}

it('round-trips a payload for the same purpose', function (): void {
	$t = signedToken();

	expect($t->verify('download', $t->sign('download', ['o' => 'abc'], 1_800_000_060)))->toBe(['o' => 'abc']);
});

it('refuses a token minted for another purpose', function (): void {
	$t = signedToken();

	expect($t->verify('download', $t->sign('unsubscribe', ['c' => 'x'])))->toBeNull();
});

it('keeps payload keys that look like envelope keys', function (): void {
	$t = signedToken();

	expect($t->verify('x', $t->sign('x', ['p' => 'brochure', 'exp' => 1, 'pur' => 'y'])))->toBe(['p' => 'brochure', 'exp' => 1, 'pur' => 'y']);
});

it('accepts a token with no expiry years later', function (): void {
	$token = signedToken()->sign('unsubscribe', ['c' => 'x']);

	expect(signedToken(now: 1_800_000_000 + 10 * 365 * 86400)->verify('unsubscribe', $token))->toBe(['c' => 'x']);
});

it('refuses an expired token', function (): void {
	$token = signedToken()->sign('download', ['o' => 'abc'], 1_800_000_060);

	expect(signedToken(now: 1_800_000_060)->verify('download', $token))->toBe(['o' => 'abc'])
		->and(signedToken(now: 1_800_000_061)->verify('download', $token))->toBeNull();
});

it('refuses a token with one changed byte', function (): void {
	$t     = signedToken();
	$token = $t->sign('x', ['a' => 'hello']);
	[$body, $sig] = explode('.', $token);

	$flip = static fn (string $s): string => substr($s, 0, 3) . ($s[3] === 'A' ? 'B' : 'A') . substr($s, 4);

	expect($t->verify('x', $flip($body) . '.' . $sig))->toBeNull()
		->and($t->verify('x', $body . '.' . $flip($sig)))->toBeNull();
});

it('refuses garbage, an empty string and an oversize token', function (): void {
	$t = signedToken();

	foreach (['', 'garbage', 'a.b', '..', '.', 'a.b.c', str_repeat('A', 5000) . '.' . 'B'] as $bad) {
		expect($t->verify('x', $bad))->toBeNull();
	}
});

it('refuses a validly signed body that is not a token envelope', function (): void {
	// Same key, but the body is JSON this class never produces.
	$key  = hash_hmac('sha256', 'signed-token', 'site-key-one', true);
	$enc  = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
	$body = $enc('"just a string"');

	expect(signedToken()->verify('x', $body . '.' . $enc(hash_hmac('sha256', $body, $key, true))))->toBeNull();
});

it('uses only URL-safe characters', function (): void {
	expect(signedToken()->sign('x', ['a' => str_repeat('?', 50) . 'ÿ>>']))->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/');
});

it('refuses tokens from another site key', function (): void {
	$token = signedToken('site-key-one')->sign('x', ['a' => 1]);

	expect(signedToken('site-key-two')->verify('x', $token))->toBeNull();
});

it('cannot sign without a site key, and verifies nothing', function (): void {
	$token = signedToken()->sign('x', ['a' => 1]);

	expect(fn () => signedToken(null)->sign('x', ['a' => 1]))->toThrow(RuntimeException::class)
		->and(signedToken(null)->verify('x', $token))->toBeNull();
});

it('is built by the container on the site key', function (): void {
	$tokens = bootstrap()->getContainer()->get(SignedToken::class);

	expect($tokens->verify('x', $tokens->sign('x', ['a' => 1])))->toBe(['a' => 1]);
});
