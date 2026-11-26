<?php

declare(strict_types=1);

use TotalCMS\Domain\Webhook\HmacSignature;

// A webhook's sender signs the exact bytes of the body with a shared secret.
// This checks such a signature, in constant time.

beforeEach(function (): void {
	$this->body   = '{ "id":"evt_1",  "type":"payment.succeeded" }';
	$this->secret = 'whsec_test_secret';
});

it('matches a hex signature, as Stripe sends', function (): void {
	$signature = hash_hmac('sha256', $this->body, $this->secret);

	expect(HmacSignature::matches($this->body, $this->secret, $signature))->toBeTrue()
		// Hex is hex in either case.
		->and(HmacSignature::matches($this->body, $this->secret, strtoupper($signature)))->toBeTrue();
});

it('matches a known vector', function (): void {
	// RFC 4231, test case 2.
	expect(HmacSignature::matches('what do ya want for nothing?', 'Jefe', '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843'))->toBeTrue();
});

it('matches a base64 signature', function (): void {
	$signature = base64_encode(hash_hmac('sha256', $this->body, $this->secret, true));

	expect(HmacSignature::matches($this->body, $this->secret, $signature, encoding: 'base64'))->toBeTrue();
});

it('matches with another algorithm when asked', function (): void {
	expect(HmacSignature::matches($this->body, $this->secret, hash_hmac('sha512', $this->body, $this->secret), 'sha512'))->toBeTrue()
		->and(HmacSignature::matches($this->body, $this->secret, hash_hmac('sha512', $this->body, $this->secret)))->toBeFalse();
});

it('refuses a body, a secret or a signature that is one byte off', function (): void {
	$signature = hash_hmac('sha256', $this->body, $this->secret);

	expect(HmacSignature::matches($this->body . ' ', $this->secret, $signature))->toBeFalse()
		->and(HmacSignature::matches($this->body, $this->secret . 'x', $signature))->toBeFalse()
		->and(HmacSignature::matches($this->body, $this->secret, substr($signature, 0, -1) . ($signature[-1] === 'a' ? 'b' : 'a')))->toBeFalse();
});

it('refuses instead of failing on anything it cannot check', function (string $signature, string $algo, string $encoding): void {
	expect(HmacSignature::matches($this->body, $this->secret, $signature, $algo, $encoding))->toBeFalse();
})->with([
	'an empty signature'    => ['', 'sha256', 'hex'],
	'an unknown algorithm'  => ['abc', 'sha-nope', 'hex'],
	'an unknown encoding'   => ['abc', 'sha256', 'rot13'],
	'base64 that is not'    => ['!!!not base64!!!', 'sha256', 'base64'],
]);

it('refuses an empty secret: a signature anyone can make proves nothing', function (): void {
	expect(HmacSignature::matches($this->body, '', hash_hmac('sha256', $this->body, '')))->toBeFalse();
});
