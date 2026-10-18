<?php

declare(strict_types=1);

use TotalCMS\Support\SameOriginRedirect;

// The one open-redirect rule every externally supplied redirect goes through:
// the post-login `redirect` and the registration form's `verifyRedirect`.

describe('SameOriginRedirect::resolve', function (): void {
	test('a relative path passes through', function (): void {
		expect(SameOriginRedirect::resolve('/welcome?ref=signup', 'totalcms.co', '/fallback'))->toBe('/welcome?ref=signup');
	});

	test('a protocol-relative URL is off-site and falls back', function (): void {
		expect(SameOriginRedirect::resolve('//evil.example/phish', 'totalcms.co', '/fallback'))->toBe('/fallback');
	});

	test('another host falls back', function (): void {
		expect(SameOriginRedirect::resolve('https://evil.example/phish', 'totalcms.co', '/fallback'))->toBe('/fallback');
	});

	test('the same host over https passes through', function (): void {
		expect(SameOriginRedirect::resolve('https://totalcms.co/members', 'totalcms.co', '/fallback'))->toBe('https://totalcms.co/members');
	});

	test('the host comparison ignores case', function (): void {
		expect(SameOriginRedirect::resolve('http://TotalCMS.co/members', 'totalcms.co', '/fallback'))->toBe('http://TotalCMS.co/members');
	});

	test('a non-http scheme falls back', function (): void {
		expect(SameOriginRedirect::resolve('javascript:alert(1)', 'totalcms.co', '/fallback'))->toBe('/fallback');
	});

	test('an empty candidate returns the fallback', function (): void {
		expect(SameOriginRedirect::resolve('', 'totalcms.co', '/fallback'))->toBe('/fallback')
			->and(SameOriginRedirect::resolve('', 'totalcms.co', ''))->toBe('');
	});

	test('backslash and control-character shapes fall back', function (string $candidate): void {
		expect(SameOriginRedirect::resolve($candidate, 'example.com', '/fallback'))->toBe('/fallback');
	})->with([
		'backslash after slash'     => ['/\evil.com'],
		'tab inside'                => ["/\t/evil.com"],
		'backslash before userinfo' => ['https://evil.com\@example.com'],
		'CRLF header injection'     => ["/foo\r\nX: y"],
		'NUL byte'                  => ["/foo\x00bar"],
	]);

	test('other off-site shapes stay rejected', function (string $candidate): void {
		expect(SameOriginRedirect::resolve($candidate, 'example.com', '/fallback'))->toBe('/fallback');
	})->with([
		'scheme without slashes' => ['https:evil.com'],
		'encoded double slash'   => ['%2F%2Fevil.com'],
		'triple slash'           => ['///evil.com'],
		'userinfo trick'         => ['https://example.com@evil.com'],
		'javascript scheme'      => ['javascript:alert(1)'],
	]);

	test('surrounding whitespace is trimmed', function (): void {
		expect(SameOriginRedirect::resolve(' /login ', 'example.com', '/fallback'))->toBe('/login');
	});

	test('a path with query and fragment passes through', function (): void {
		expect(SameOriginRedirect::resolve('/login?x=1#y', 'example.com', '/fallback'))->toBe('/login?x=1#y');
	});
});
