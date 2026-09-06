<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Storage;

/**
 * What a malformed or unreadable JSON document means to its owner.
 *
 * This is a required argument at every call site on purpose. "Is losing this
 * file acceptable?" used to be decided silently inside each repository, and
 * one of those silent decisions deleted every API key on production installs.
 */
enum CorruptPolicy
{
	/** Credentials and anything else where reading nothing must fail loudly. */
	case Throw;

	/**
	 * State that the request can run without, but that must never be
	 * overwritten by the empty value we substituted for it. Reads return [],
	 * saves are refused (logged) until a later read succeeds.
	 */
	case RefuseWrites;

	/** Genuinely disposable files, where starting fresh loses nothing. */
	case TreatAsEmpty;
}
