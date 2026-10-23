<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\BotProtection;

describe('BotProtection', function (): void {
	$now = 1_800_000_000;

	$body = static fn (string $token, string $honeypot = ''): array => [
		'email'                          => 'a@b.test',
		BotProtection::HONEYPOT_FIELD    => $honeypot,
		BotProtection::TIMESTAMP_FIELD   => $token,
	];

	test('a token checked after MIN_SECONDS passes', function () use ($now, $body): void {
		$bot   = new BotProtection('site-key');
		$token = $bot->issue('members', 'register', $now);

		expect($bot->check($body($token), 'members', 'register', $now + BotProtection::MIN_SECONDS))->toBe('');
		expect($bot->check($body($token), 'members', 'register', $now + BotProtection::MAX_AGE_SECONDS))->toBe('');
	});

	test('a submission sooner than MIN_SECONDS is too_fast', function () use ($now, $body): void {
		$bot   = new BotProtection('site-key');
		$token = $bot->issue('members', 'register', $now);

		expect($bot->check($body($token), 'members', 'register', $now + BotProtection::MIN_SECONDS - 1))->toBe('too_fast');
		// A token from the future is no older than "now".
		expect($bot->check($body($bot->issue('members', 'register', $now + 60)), 'members', 'register', $now))->toBe('too_fast');
	});

	test('a token older than MAX_AGE_SECONDS is expired', function () use ($now, $body): void {
		$bot   = new BotProtection('site-key');
		$token = $bot->issue('members', 'register', $now);

		expect($bot->check($body($token), 'members', 'register', $now + BotProtection::MAX_AGE_SECONDS + 1))->toBe('expired');
	});

	test('a token fails bad_signature for another collection, form type, key or a tampered value', function () use ($now, $body): void {
		$bot   = new BotProtection('site-key');
		$token = $bot->issue('members', 'register', $now);
		$later = $now + 10;

		expect($bot->check($body($token), 'other', 'register', $later))->toBe('bad_signature');
		expect($bot->check($body($token), 'members', 'create', $later))->toBe('bad_signature');
		expect((new BotProtection('another-key'))->check($body($token), 'members', 'register', $later))->toBe('bad_signature');

		// Backdating the timestamp breaks the signature.
		[, $signature] = explode('.', $token);
		expect($bot->check($body(($now - 100) . '.' . $signature), 'members', 'register', $later))->toBe('bad_signature');

		expect($bot->check($body('not-a-token'), 'members', 'register', $later))->toBe('bad_signature');
		expect($bot->check($body($now . '.' . str_repeat('0', 64)), 'members', 'register', $later))->toBe('bad_signature');
	});

	test('a filled honeypot fails honeypot; whitespace counts as empty', function () use ($now, $body): void {
		$bot   = new BotProtection('site-key');
		$token = $bot->issue('members', 'register', $now);
		$later = $now + 10;

		expect($bot->check($body($token, 'https://spam.example'), 'members', 'register', $later))->toBe('honeypot');
		expect($bot->check([BotProtection::HONEYPOT_FIELD => ['x'], BotProtection::TIMESTAMP_FIELD => $token], 'members', 'register', $later))->toBe('honeypot');
		expect($bot->check($body($token, "  \n"), 'members', 'register', $later))->toBe('');
	});

	test('a missing or empty token fails missing', function () use ($now): void {
		$bot = new BotProtection('site-key');

		expect($bot->check(['email' => 'a@b.test'], 'members', 'register', $now))->toBe('missing');
		expect($bot->check([BotProtection::HONEYPOT_FIELD => '', BotProtection::TIMESTAMP_FIELD => ''], 'members', 'register', $now))->toBe('missing');
	});

	test('strip removes both bot fields and nothing else; present sees either', function () use ($body): void {
		$bot = new BotProtection('site-key');

		expect($bot->strip($body('t')))->toBe(['email' => 'a@b.test']);
		expect($bot->present(['email' => 'a@b.test']))->toBeFalse();
		expect($bot->present([BotProtection::HONEYPOT_FIELD => '']))->toBeTrue();
		expect($bot->present([BotProtection::TIMESTAMP_FIELD => '']))->toBeTrue();
	});

	test('the token is "<unix>.<hmac-sha256>" over time, collection and form type, keyed by a subkey', function () use ($now): void {
		$token  = (new BotProtection('site-key'))->issue('members', 'register', $now);
		$subkey = hash_hmac('sha256', 'bot-protection', 'site-key');

		expect($token)->toBe($now . '.' . hash_hmac('sha256', "{$now}|members|register", $subkey));
		// Never signed with the raw site key.
		expect($token)->not->toBe($now . '.' . hash_hmac('sha256', "{$now}|members|register", 'site-key'));
	});
});
