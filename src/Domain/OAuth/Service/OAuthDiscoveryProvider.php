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
final readonly class OAuthDiscoveryProvider
{
	public function __construct(
		private Config $config,
		private OAuthScopeRegistry $scopes,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function metadata(): array
	{
		$issuer     = $this->resolveIssuer();
		$grantTypes = (array)($this->config->oauth['allowedGrantTypes'] ?? ['authorization_code', 'refresh_token']);
		$dynamic    = (bool)($this->config->oauth['dynamicRegistration'] ?? false);

		$meta = [
			'issuer'                                => $issuer,
			'authorization_endpoint'                => $issuer . '/oauth/authorize',
			'token_endpoint'                        => $issuer . '/oauth/token',
			'revocation_endpoint'                   => $issuer . '/oauth/revoke',
			'jwks_uri'                              => $issuer . '/.well-known/jwks.json',
			'scopes_supported'                      => array_map(fn (\TotalCMS\Domain\OAuth\Data\OAuthScopeData $s): string => $s->identifier, $this->scopes->all()),
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

	/**
	 * RFC 9728 OAuth 2.0 Protected Resource Metadata.
	 *
	 * Served at `/.well-known/oauth-protected-resource` and pointed to by the MCP
	 * endpoint's `WWW-Authenticate: ... resource_metadata="..."` challenge, so a
	 * client that hits a 401 can discover which authorization server protects this
	 * resource. T3 is its own authorization server, so `authorization_servers`
	 * lists this issuer. Returned as the SDK value object so serialization
	 * tracks the spec; `bearer_methods_supported` rides in `extra`.
	 */
	public function protectedResourceMetadata(): \Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata
	{
		$resource = rtrim($this->config->url, '/') . rtrim($this->config->api, '/');

		return new \Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata(
			authorizationServers: [$this->resolveIssuer()],
			scopesSupported: array_map(fn (\TotalCMS\Domain\OAuth\Data\OAuthScopeData $s): string => $s->identifier, $this->scopes->all()),
			resource: $resource,
			extra: ['bearer_methods_supported' => ['header']],
		);
	}

	private function resolveIssuer(): string
	{
		// Prefer explicit oauth.jwtIssuer (operator supplies a full URL); fall
		// back to $config->url which holds T3's canonical scheme+host.
		$issuer = (string)($this->config->oauth['jwtIssuer'] ?? '');
		if ($issuer !== '') {
			return rtrim($issuer, '/');
		}

		// $config->url is scheme+host only — it does NOT carry the subpath the
		// app is mounted under. On a subpath install (e.g. /tcms) the OAuth
		// routes live at {host}/tcms/oauth/*, so the discovery document must
		// advertise that base or clients hit 404s. $config->api is that mount
		// prefix ('' for a root install, '/tcms' for a subfolder install).
		return rtrim($this->config->url, '/') . rtrim($this->config->api, '/');
	}
}
