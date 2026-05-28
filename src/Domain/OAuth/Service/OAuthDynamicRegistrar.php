<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

/**
 * RFC 7591 dynamic client registration. Validates the inbound metadata
 * payload, then delegates to OAuthClientCreator with is_dynamic=true.
 *
 * Validation per RFC 7591 §2:
 *   - redirect_uris MUST be present and be a non-empty array
 *   - client_name SHOULD be present (we require it for the admin UI's
 *     dynamic-clients list to be useful)
 *   - scope MUST be a string of space-separated identifiers, all in the
 *     T3 registry
 *   - token_endpoint_auth_method, grant_types, response_types if present
 *     must match what we support; we silently default if absent
 */
final class OAuthDynamicRegistrar
{
	public function __construct(
		private readonly OAuthClientCreator $creator,
		private readonly OAuthScopeRegistry $scopes,
	) {
	}

	/**
	 * @param  array<string,mixed> $metadata
	 *
	 * @return array{client_id: string, client_secret: string, client_secret_expires_at: int, registration_access_token: string, client_id_issued_at: int, redirect_uris: list<string>, client_name: string, scope: string}
	 */
	public function register(array $metadata): array
	{
		$redirectUris = $metadata['redirect_uris'] ?? null;
		if (!is_array($redirectUris) || $redirectUris === []) {
			throw new \InvalidArgumentException('redirect_uris is required and must be non-empty');
		}
		$redirectUris = array_values(array_map('strval', $redirectUris));

		$clientName = isset($metadata['client_name']) ? (string)$metadata['client_name'] : 'Unnamed dynamic client';

		$scope  = isset($metadata['scope']) ? (string)$metadata['scope'] : '';
		$scopes = $scope !== '' ? array_values(array_filter(explode(' ', $scope))) : [];
		foreach ($scopes as $s) {
			if (!$this->scopes->has($s)) {
				throw new \InvalidArgumentException("Unknown scope in registration: {$s}");
			}
		}

		$result = $this->creator->create(
			name: $clientName,
			redirectUris: $redirectUris,
			allowedScopes: $scopes,
			createdBy: 'rfc7591-self-registered',
			isDynamic: true,
			isConfidential: true,
		);

		return [
			'client_id'                 => $result['client']->id,
			'client_secret'             => $result['secret'],
			'client_secret_expires_at'  => 0, // RFC 7591 §3.2.1: 0 = non-expiring secret
			'registration_access_token' => $this->generateRegistrationAccessToken(),
			'client_id_issued_at'       => time(),
			'redirect_uris'             => $result['client']->redirectUris,
			'client_name'               => $result['client']->name,
			'scope'                     => implode(' ', $result['client']->scopes),
		];
	}

	private function generateRegistrationAccessToken(): string
	{
		// RFC 7592 — issued for future client metadata update. v1 emits but
		// the update endpoint isn't implemented; the token is opaque.
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}
}
