<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/**
 * The oauth.enabled switch: a site can turn its OAuth server off entirely to
 * run public-only MCP. Claude's consumer apps front-load a login prompt
 * whenever OAuth is discoverable — even though the anonymous tier would have
 * served them — and their consent screen is impassable for visitors without
 * an operator account. With OAuth undiscoverable and its endpoints 404ing,
 * those clients connect anonymously like they do to any no-auth MCP server.
 *
 * The switch must kill discovery AND issuance. Hiding the well-knowns while
 * leaving /oauth/token alive would let previously-issued refresh tokens keep
 * minting access tokens forever.
 */
beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	/** @var Config $config */
	$config        = $this->app->getContainer()->get(Config::class);
	$config->oauth = array_merge($config->oauth, ['enabled' => false]);
});

describe('OAuth disabled', function (): void {
	it('404s the authorization-server metadata', function (): void {
		expect(get('/.well-known/oauth-authorization-server')->getStatusCode())->toBe(404);
	});

	it('404s the protected-resource metadata', function (): void {
		expect(get('/.well-known/oauth-protected-resource')->getStatusCode())->toBe(404);
	});

	it('404s the JWKS document', function (): void {
		expect(get('/.well-known/jwks.json')->getStatusCode())->toBe(404);
	});

	it('404s the authorize endpoint', function (): void {
		expect(get('/oauth/authorize?client_id=x')->getStatusCode())->toBe(404);
	});

	it('404s the token endpoint', function (): void {
		expect(postJson('/oauth/token', ['grant_type' => 'authorization_code'])->getStatusCode())->toBe(404);
	});

	it('404s dynamic registration even when the registration flag is on', function (): void {
		/** @var Config $config */
		$config        = $this->app->getContainer()->get(Config::class);
		$config->oauth = array_merge($config->oauth, ['dynamicRegistration' => true]);

		$response = postJson('/oauth/register', [
			'client_name'   => 'probe',
			'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
		]);

		expect($response->getStatusCode())->toBe(404);
	});

	it('404s the revocation endpoint', function (): void {
		expect(postJson('/oauth/revoke', ['token' => 'x'])->getStatusCode())->toBe(404);
	});

	it('drops resourceMetadata from the MCP discovery document', function (): void {
		$payload = json_decode((string)get('/.well-known/mcp.json')->getBody(), true);

		expect($payload)->toBeArray();
		expect($payload)->not->toHaveKey('resourceMetadata');
	});
});
