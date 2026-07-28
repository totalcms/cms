<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\CSRF;

/**
 * What the browser-set Origin/Referer headers say about where a request came
 * from.
 *
 * Three states, not two: callers need to tell "the browser told us this came
 * from somewhere else" (a hard reject) apart from "there are no browser headers
 * to judge by" (fall back to another check, or deny, depending on the caller).
 */
enum OriginVerdict
{
	/** Browser-verified as originating from this site. */
	case SameOrigin;

	/** Browser-verified as originating somewhere else. */
	case CrossOrigin;

	/** No usable headers — typically a non-browser caller. */
	case Unknown;
}
