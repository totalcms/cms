<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;

/**
 * Creates new OAuth clients. Generates UUID id + 64-char URL-safe random
 * secret; bcrypt-hashes the secret before persisting. Returns the plaintext
 * secret EXACTLY ONCE in the result tuple — never recoverable afterward.
 */
final class OAuthClientCreator
{
	public function __construct(
		private readonly OAuthClientRepository $clients,
		private readonly OAuthScopeRegistry $scopes,
		private readonly OAuthActivityLogger $activityLogger,
	) {
	}

	/**
	 * @param  list<string> $redirectUris
	 * @param  list<string> $allowedScopes
	 * @return array{client: OAuthClientData, secret: string}  plaintext secret shown once
	 */
	public function create(
		string $name,
		array $redirectUris,
		array $allowedScopes,
		string $createdBy,
		bool $isDynamic = false,
		bool $isConfidential = true,
		?string $iconPath = null,
	): array {
		foreach ($allowedScopes as $scope) {
			if (!$this->scopes->has($scope)) {
				throw new \InvalidArgumentException("Unknown scope: {$scope}");
			}
		}

		foreach ($redirectUris as $uri) {
			$this->assertValidRedirectUri($uri);
		}

		$id     = $this->generateUuid();
		$secret = $this->generateSecret();
		$hash   = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 12]);

		$client = new OAuthClientData(
			id:              $id,
			name:            $name,
			secretHash:      $hash,
			redirectUris:    $redirectUris,
			scopes:          $allowedScopes,
			isDynamic:       $isDynamic,
			isConfidential:  $isConfidential,
			createdAt:       gmdate('c'),
			createdBy:       $createdBy,
			iconPath:        $iconPath,
		);
		$this->clients->save($client);
		$this->activityLogger->clientCreated($client->id, $client->name, $client->isDynamic, $createdBy);

		return ['client' => $client, 'secret' => $secret];
	}

	private function generateUuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	private function generateSecret(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
	}

	private function assertValidRedirectUri(string $uri): void
	{
		$parts = parse_url($uri);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			throw new \InvalidArgumentException("Invalid redirect URI: {$uri}");
		}
		$scheme = strtolower($parts['scheme']);
		$host   = strtolower($parts['host']);
		if ($scheme === 'https') {
			return;
		}
		if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
			return;
		}
		// Custom schemes (claude://, etc.) allowed for native-app clients.
		if (!in_array($scheme, ['http', 'https'], true)) {
			return;
		}
		throw new \InvalidArgumentException("Redirect URI must be HTTPS (or http://localhost for dev): {$uri}");
	}
}
