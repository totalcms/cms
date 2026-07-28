<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\Security\CSRF\OriginVerdict;
use TotalCMS\Domain\Security\CSRF\RequestOriginValidator;
use TotalCMS\Support\Config;

// The browser stamps Origin on every state-changing request and script cannot
// forge it (it's a forbidden header name), so a matching Origin proves the
// request came from our own pages — the same property a CSRF token proves
// indirectly. The comparison is only as trustworthy as the host it compares
// against, so the candidate hosts must never come from attacker-supplied input.

function originValidator(string $domain = 'example.com'): RequestOriginValidator
{
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = $domain;

	return new RequestOriginValidator($config);
}

function originRequest(string $uri = 'https://example.com/api/collections/blog/post-1'): Psr\Http\Message\ServerRequestInterface
{
	return (new ServerRequestFactory())->createServerRequest('POST', $uri);
}

// ---------------------------------------------------------------------------
// Origin header — authoritative
// ---------------------------------------------------------------------------

it('reports same-origin when Origin matches the configured domain', function (): void {
	$request = originRequest()->withHeader('Origin', 'https://example.com');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::SameOrigin);
});

it('reports cross-origin for a different site', function (): void {
	$request = originRequest()->withHeader('Origin', 'https://evil.com');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::CrossOrigin);
});

it('reports cross-origin for a same-site sibling subdomain', function (): void {
	// SameSite=Lax lets this through — an Origin check is what catches it.
	$request = originRequest()->withHeader('Origin', 'https://evil.example.com');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::CrossOrigin);
});

it('reports cross-origin for the literal null origin', function (): void {
	// Sandboxed iframes and some redirect chains send Origin: null.
	$request = originRequest()->withHeader('Origin', 'null');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::CrossOrigin);
});

it('ignores the port when comparing hosts', function (): void {
	$request = originRequest('https://example.com:8443/api/x')->withHeader('Origin', 'https://example.com:8443');

	expect(originValidator('example.com:8443')->verdict($request))->toBe(OriginVerdict::SameOrigin);
});

it('matches the request host when the configured domain is stale', function (): void {
	// Behind a proxy the configured domain can be a bridge IP; the Host header
	// the browser sent is still truthful, so either candidate may match.
	$request = originRequest('https://real.example.com/api/x')->withHeader('Origin', 'https://real.example.com');

	expect(originValidator('172.17.0.2')->verdict($request))->toBe(OriginVerdict::SameOrigin);
});

// ---------------------------------------------------------------------------
// Referer fallback — only when Origin is absent
// ---------------------------------------------------------------------------

it('falls back to Referer when Origin is absent', function (): void {
	$request = originRequest()->withHeader('Referer', 'https://example.com/admin/collection/blog');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::SameOrigin);
});

it('rejects a mismatched Referer when Origin is absent', function (): void {
	$request = originRequest()->withHeader('Referer', 'https://evil.com/attack.html');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::CrossOrigin);
});

it('lets a present Origin decide even when Referer disagrees', function (): void {
	$request = originRequest()
		->withHeader('Origin', 'https://evil.com')
		->withHeader('Referer', 'https://example.com/admin');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::CrossOrigin);
});

// ---------------------------------------------------------------------------
// Unknown — no browser headers to judge by
// ---------------------------------------------------------------------------

it('reports unknown when neither header is present', function (): void {
	expect(originValidator()->verdict(originRequest()))->toBe(OriginVerdict::Unknown);
});

it('reports unknown when there is no host to compare against', function (): void {
	$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/collections')
		->withHeader('Origin', 'https://example.com');

	expect(originValidator('')->verdict($request))->toBe(OriginVerdict::Unknown);
});

// ---------------------------------------------------------------------------
// X-Forwarded-Host is attacker-supplied and must never be a candidate
// ---------------------------------------------------------------------------

it('ignores X-Forwarded-Host so a caller cannot nominate its own origin', function (): void {
	$request = originRequest()
		->withHeader('Origin', 'https://evil.com')
		->withHeader('X-Forwarded-Host', 'evil.com');

	expect(originValidator()->verdict($request))->toBe(OriginVerdict::CrossOrigin);
});

// ---------------------------------------------------------------------------
// isSameOrigin() — the boolean the webhook middleware consumes
// ---------------------------------------------------------------------------

it('isSameOrigin is true only for a verified same-origin request', function (): void {
	$validator = originValidator();

	expect($validator->isSameOrigin(originRequest()->withHeader('Origin', 'https://example.com')))->toBeTrue();
	expect($validator->isSameOrigin(originRequest()->withHeader('Origin', 'https://evil.com')))->toBeFalse();
	// Unknown collapses to false — a non-browser caller we can't verify.
	expect($validator->isSameOrigin(originRequest()))->toBeFalse();
});
