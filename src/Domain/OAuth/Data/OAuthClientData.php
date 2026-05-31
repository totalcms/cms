<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Data;

/**
 * Value object describing an OAuth client (a third-party app that may
 * request tokens). Created either by an admin via the OAuth admin UI
 * (is_dynamic=false) or self-registered via RFC 7591 (is_dynamic=true).
 *
 * `secret_hash` is bcrypt of the plaintext secret. The plaintext is
 * shown to the admin exactly once at creation time and is never
 * recoverable afterward.
 */
readonly class OAuthClientData
{
	/**
	 * @param list<string> $redirectUris
	 * @param list<string> $scopes
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $secretHash,
		public array $redirectUris,
		public array $scopes,
		public bool $isDynamic,
		public bool $isConfidential,
		public string $createdAt,
		public string $createdBy,
		public ?string $iconPath = null,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			id: (string)$data['id'],
			name: (string)$data['name'],
			secretHash: (string)$data['secret_hash'],
			redirectUris: array_values(array_map(strval(...), (array)$data['redirect_uris'])),
			scopes: array_values(array_map(strval(...), (array)$data['scopes'])),
			isDynamic: (bool)$data['is_dynamic'],
			isConfidential: (bool)$data['is_confidential'],
			createdAt: (string)$data['created_at'],
			createdBy: (string)$data['created_by'],
			iconPath: isset($data['icon_path']) ? (string)$data['icon_path'] : null,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'id'              => $this->id,
			'name'            => $this->name,
			'secret_hash'     => $this->secretHash,
			'redirect_uris'   => $this->redirectUris,
			'scopes'          => $this->scopes,
			'is_dynamic'      => $this->isDynamic,
			'is_confidential' => $this->isConfidential,
			'created_at'      => $this->createdAt,
			'created_by'      => $this->createdBy,
			'icon_path'       => $this->iconPath,
		];
	}
}
