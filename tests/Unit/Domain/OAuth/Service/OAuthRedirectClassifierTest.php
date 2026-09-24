<?php

declare(strict_types=1);

use TotalCMS\Domain\OAuth\Service\OAuthRedirectClassifier;

describe('OAuthRedirectClassifier', function (): void {
	it('classifies a redirect target', function (string $uri, string $host, string $kind): void {
		expect((new OAuthRedirectClassifier())->classify($uri))->toBe(['host' => $host, 'kind' => $kind]);
	})->with([
		'claude.ai callback'      => ['https://claude.ai/api/mcp/auth_callback', 'claude.ai', 'known'],
		'subdomain of known host' => ['https://auth.chatgpt.com/cb', 'auth.chatgpt.com', 'known'],
		'host case is ignored'    => ['https://Claude.AI/cb', 'claude.ai', 'known'],
		'localhost with port'     => ['http://localhost:33418/callback', 'localhost', 'local'],
		'IPv4 loopback'           => ['http://127.0.0.1:6274/oauth/callback', '127.0.0.1', 'local'],
		'IPv6 loopback'           => ['http://[::1]:8080/cb', '[::1]', 'local'],
		'custom app scheme'       => ['cursor://anysphere.cursor-mcp/oauth/callback', 'cursor://', 'local'],
		'unknown web host'        => ['https://evil.example/cb', 'evil.example', 'unknown'],
		'lookalike suffix'        => ['https://notclaude.ai/cb', 'notclaude.ai', 'unknown'],
		'known name as subdomain' => ['https://claude.ai.evil.example/cb', 'claude.ai.evil.example', 'unknown'],
	]);
});
