<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

use TotalCMS\Domain\Auth\Service\AccessGuard;

/**
 * The outcome of an {@see AccessGuard} check.
 * Each entry point maps it onto its own answer: REST answers 403 for the
 * denials, MCP answers "not found" for DenyOwner so another member's ids
 * cannot be probed.
 */
enum Decision: string
{
	case Allow = 'allow';

	/** The actor's access groups do not grant the operation. */
	case DenyGroup = 'group';

	/** The object belongs to someone else, or the operation cannot be scoped to the actor's own objects. */
	case DenyOwner = 'owner';

	/** A protected, forced or owner field rule refuses it. */
	case DenyProtected = 'protected';

	/** The object does not exist; a read may go on to its usual 404. */
	case NotFound = 'not-found';

	/**
	 * Whether the entry point may let the request continue. NotFound does:
	 * the action answers its own 404.
	 */
	public function passes(): bool
	{
		return $this === self::Allow || $this === self::NotFound;
	}

	/**
	 * The `reason` written to the access log on a denial.
	 */
	public function reason(): string
	{
		return $this->value;
	}
}
