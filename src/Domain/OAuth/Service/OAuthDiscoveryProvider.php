<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use TotalCMS\Support\Config;

/**
 * Emits RFC 8414 OAuth 2.0 Authorization Server Metadata.
 *
 * Read by AI clients (Claude, Cursor) before initiating an OAuth
 * flow to discover endpoints + supported scopes + supported grant
 * types.
 */
final class OAuthDiscoveryProvider
{
	public function __construct(
		private readonly Config $config,
		private readonly OAuthScopeRegistry $scopes,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function metadata(): array
	{
		$issuer     = $this->resolveIssuer();
		$grantTypes = (array)($this->config->oauth['allowedGrantTypes'] ?? ['authorization_code', 'refresh_token']);
		$dynamic    = (bool)($this->config->oauth['dynamicRegistration'] ?? true);

		$meta = [
			'issuer'                                => $issuer,
			'authorization_endpoint'                => $issuer . '/oauth/authorize',
			'token_endpoint'                        => $issuer . '/oauth/token',
			'revocation_endpoint'                   => $issuer . '/oauth/revoke',
			'jwks_uri'                              => $issuer . '/.well-known/jwks.json',
			'scopes_supported'                      => array_map(fn ($s) => $s->identifier, $this->scopes->all()),
			'response_types_supported'              => ['code'],
			'grant_types_supported'                 => array_values($grantTypes),
			'code_challenge_methods_supported'      => (array)($this->config->oauth['pkceMethods'] ?? ['S256']),
			'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
		];

		if ($dynamic) {
			$meta['registration_endpoint'] = $issuer . '/oauth/register';
		}

		return $meta;
	}

	private function resolveIssuer(): string
	{
		// Prefer explicit oauth.jwtIssuer; fall back to $config->url which holds T3's canonical site URL.
		$issuer = (string)($this->config->oauth['jwtIssuer'] ?? '');
		if ($issuer !== '') {
			return rtrim($issuer, '/');
		}
		return rtrim($this->config->url, '/');
	}
}
