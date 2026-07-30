<?php

declare(strict_types=1);

use TotalCMS\Action\Auth\AuthLoginSubmitAction;

// The post-login redirect target can arrive via a crafted link
// (?redirect=https://evil.example) — a login page that forwards wherever
// it's told is a phishing primitive. Only local paths and same-host
// absolute URLs may survive; everything else falls back.
//
// The guard reads no instance state, so it's invoked on a bare instance —
// the action's constructor graph is irrelevant to this logic.

function loginRedirect(string $candidate, string $host = 'totalcms.co', string $fallback = '/admin'): string
{
	$action = (new ReflectionClass(AuthLoginSubmitAction::class))->newInstanceWithoutConstructor();
	$method = new ReflectionMethod(AuthLoginSubmitAction::class, 'sameOriginRedirect');

	return (string)$method->invoke($action, $candidate, $host, $fallback);
}

describe('post-login redirect guard', function (): void {
	test('relative paths pass through', function (): void {
		expect(loginRedirect('/admin/collections/blog'))->toBe('/admin/collections/blog');
	});

	test('same-host absolute URLs pass through — the OAuth consent return path', function (): void {
		$authorize = 'https://totalcms.co/oauth/authorize?response_type=code&client_id=abc&state=xyz';
		expect(loginRedirect($authorize))->toBe($authorize);
	});

	test('foreign hosts fall back to the dashboard', function (): void {
		expect(loginRedirect('https://evil.example/phish'))->toBe('/admin');
	});

	test('protocol-relative URLs are external and fall back', function (): void {
		expect(loginRedirect('//evil.example/phish'))->toBe('/admin');
	});

	test('non-http schemes fall back', function (): void {
		expect(loginRedirect('javascript:alert(1)'))->toBe('/admin');
	});

	test('host comparison is case-insensitive', function (): void {
		expect(loginRedirect('https://TotalCMS.co/admin'))->toBe('https://TotalCMS.co/admin');
	});

	test('empty target falls back', function (): void {
		expect(loginRedirect(''))->toBe('/admin');
	});
});
