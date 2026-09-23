<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Data;

/**
 * The `mcp.access` level a collection, data view, prompt or tool is exposed
 * at, and the one place that decides which personas it lets in.
 *
 * `public` — everyone, including anonymous connections.
 * `authenticated` — any OAuth-authenticated connection, plus admins.
 * `admin` — admin connections only.
 *
 * This rule used to be a three-arm `match` copied into nine classes, and the
 * copies disagreed on what an unrecognised value meant: most read it as
 * `admin`, one read it as "nobody". Unknown strings fail closed to ADMIN
 * here, so a malformed setting can never widen exposure — and cannot lock
 * an admin out either.
 */
enum McpAccessLevel: string
{
	case PUBLIC_       = 'public';
	case AUTHENTICATED = 'authenticated';
	case ADMIN         = 'admin';

	/**
	 * Resolve a raw setting value. Anything that is not one of the three
	 * levels — including '' and null — is treated as `admin`.
	 */
	public static function fromString(?string $access): self
	{
		return self::tryFrom((string)$access) ?? self::ADMIN;
	}

	public function allows(McpPersona $persona): bool
	{
		return match ($this) {
			self::PUBLIC_       => true,
			self::AUTHENTICATED => $persona !== McpPersona::PUBLIC_,
			self::ADMIN         => $persona === McpPersona::ADMIN,
		};
	}
}
