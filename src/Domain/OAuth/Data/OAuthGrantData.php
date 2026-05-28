<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Data;

/**
 * Value object describing an active OAuth grant — the persisted record
 * created when an admin approves a consent screen and exchanged into
 * tokens. Carries the refresh-token hash; access tokens are stateless
 * JWTs so they're not stored here.
 */
readonly class OAuthGrantData
{
	/**
	 * @param list<string> $scopes
	 */
	public function __construct(
		public string $id,
		public string $clientId,
		public string $userId,
		public array $scopes,
		public string $refreshTokenHash,
		public string $issuedAt,
		public string $expiresAt,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			id: (string)$data['id'],
			clientId: (string)$data['client_id'],
			userId: (string)$data['user_id'],
			scopes: array_values(array_map('strval', (array)$data['scopes'])),
			refreshTokenHash: (string)$data['refresh_token_hash'],
			issuedAt: (string)$data['issued_at'],
			expiresAt: (string)$data['expires_at'],
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'id'                 => $this->id,
			'client_id'          => $this->clientId,
			'user_id'            => $this->userId,
			'scopes'             => $this->scopes,
			'refresh_token_hash' => $this->refreshTokenHash,
			'issued_at'          => $this->issuedAt,
			'expires_at'         => $this->expiresAt,
		];
	}
}
