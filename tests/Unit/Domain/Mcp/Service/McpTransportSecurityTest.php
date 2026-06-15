<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use TotalCMS\Domain\Mcp\Service\McpTransportSecurity;

it('is open when the allowlist is empty', function (): void {
	expect(McpTransportSecurity::isOpen([]))->toBeTrue();
});

it('is open when the allowlist contains a wildcard', function (): void {
	expect(McpTransportSecurity::isOpen(['*']))->toBeTrue();
	expect(McpTransportSecurity::isOpen(['https://claude.ai', '*']))->toBeTrue();
});

it('is restricted when explicit origins are listed', function (): void {
	expect(McpTransportSecurity::isOpen(['https://claude.ai']))->toBeFalse();
});

it('ignores blank and whitespace-only entries when deciding open', function (): void {
	expect(McpTransportSecurity::isOpen(['', '   ']))->toBeTrue();
});

it('always includes the server host so production and same-origin requests are not blocked', function (): void {
	$hosts = McpTransportSecurity::allowedHosts(['https://claude.ai'], 'mysite.com');

	expect($hosts)->toContain('mysite.com');
	expect($hosts)->toContain('claude.ai');
});

it('excludes a disallowed origin host from the allowlist', function (): void {
	$hosts = McpTransportSecurity::allowedHosts(['https://app.example.com'], 'mysite.com');

	// This is what makes DnsRebindingProtectionMiddleware 403 an evil origin.
	expect($hosts)->not->toContain('evil.example');
	expect($hosts)->toEqual(['mysite.com', 'app.example.com']);
});

it('lowercases and de-duplicates hosts', function (): void {
	$hosts = McpTransportSecurity::allowedHosts(['https://App.Example.com', 'https://app.example.com'], 'MySite.com');

	expect($hosts)->toEqual(['mysite.com', 'app.example.com']);
});

it('drops an empty server host', function (): void {
	$hosts = McpTransportSecurity::allowedHosts(['https://claude.ai'], '');

	expect($hosts)->toEqual(['claude.ai']);
});
