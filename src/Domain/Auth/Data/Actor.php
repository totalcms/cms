<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

/**
 * Who is acting on the current request or run: the identity every access
 * decision in {@see \TotalCMS\Domain\Auth\Service\AccessGuard} is made for.
 *
 * `authCollection` is always resolved: a session user who logged in at the
 * plain `/admin/login` route carries the default auth collection here, never
 * `''`. `authority` is the user's access-group grants (null for the trusted
 * kinds that need none, and for anonymous). `scopes` is the OAuth token's
 * scopes, or null when the actor did not arrive with a token.
 *
 * The rest says how the actor arrived, for the record rather than for any
 * decision: `clientId` is the OAuth client (or `webmcp` for a browser
 * session over MCP), `apiKeyId` the API key, and `initiator` who started
 * work the system now does on their behalf (see
 * {@see \TotalCMS\Domain\Auth\Service\ActorContext::runAs()}).
 *
 * Built by {@see \TotalCMS\Domain\Auth\Service\ActorResolver}; the current
 * one lives in {@see \TotalCMS\Domain\Auth\Service\ActorContext}.
 */
final readonly class Actor
{
	/**
	 * @param list<string>|null $scopes
	 */
	public function __construct(
		public ActorKind $kind,
		public string $userId = '',
		public string $authCollection = '',
		public ?UserAuthority $authority = null,
		public ?array $scopes = null,
		public string $clientId = '',
		public string $apiKeyId = '',
		public ?Actor $initiator = null,
	) {
	}

	public static function system(): self
	{
		return new self(ActorKind::System);
	}

	public static function anonymous(): self
	{
		return new self(ActorKind::Anonymous);
	}

	public static function apiKey(string $apiKeyId = ''): self
	{
		return new self(ActorKind::ApiKey, apiKeyId: $apiKeyId);
	}

	public function isTrusted(): bool
	{
		return $this->kind->isTrusted();
	}

	/**
	 * Whether the actor arrived with an OAuth token rather than a session.
	 */
	public function isBearer(): bool
	{
		return $this->scopes !== null;
	}

	/**
	 * The actor's access-group grants; a denying authority when none was
	 * resolved, so a caller can always ask.
	 */
	public function authority(): UserAuthority
	{
		return $this->authority ?? UserAuthority::denied();
	}

	/**
	 * This actor, recorded as acting for `$by`. The initiator is always the
	 * outermost one: work started by a member that elevates twice still names
	 * the member. The plain system actor (the CLI, a cron run) starts nothing
	 * worth recording, so it leaves the actor as it is.
	 */
	public function startedBy(self $by): self
	{
		$initiator = $by->initiator ?? $by;
		if ($initiator->kind === ActorKind::System) {
			return $this;
		}

		return new self($this->kind, $this->userId, $this->authCollection, $this->authority, $this->scopes, $this->clientId, $this->apiKeyId, $initiator);
	}

	/**
	 * The actor as plain data, for event payloads and logs. Never carries the
	 * authority or scopes: a record says who acted, not what they could do.
	 *
	 * @return array{kind: string, userId: string, authCollection: string, clientId: string, apiKeyId: string, initiator: array<string,mixed>|null}
	 */
	public function toArray(): array
	{
		return [
			'kind'           => $this->kind->value,
			'userId'         => $this->userId,
			'authCollection' => $this->authCollection,
			'clientId'       => $this->clientId,
			'apiKeyId'       => $this->apiKeyId,
			'initiator'      => $this->initiator?->toArray(),
		];
	}
}
