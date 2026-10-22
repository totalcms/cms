<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

/**
 * What kind of caller an {@see Actor} is. The first three are trusted: the
 * field policy and the owner checks never apply to them.
 */
enum ActorKind: string
{
	/** CLI commands, job and automation runs, internal bookkeeping writes. */
	case System = 'system';

	/** An operator-issued API key. */
	case ApiKey = 'apikey';

	/** A super admin, by session or by an admin OAuth token. */
	case Admin = 'admin';

	/** A signed-in user who is not a super admin, by session or OAuth token. */
	case Member = 'member';

	/** Nobody signed in: a public form submission or an unauthenticated read. */
	case Anonymous = 'anonymous';

	public function isTrusted(): bool
	{
		return $this === self::System || $this === self::ApiKey || $this === self::Admin;
	}
}
