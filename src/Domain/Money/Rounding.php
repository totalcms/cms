<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Money;

/**
 * How {@see Money} turns a fraction of a minor unit into a whole one.
 *
 * Every mode is symmetric about zero: a refund of -10.885 rounds to the same
 * distance from zero as a charge of 10.885, so the two cancel.
 */
enum Rounding
{
	/** To the nearest unit; exactly half goes away from zero. The default everywhere. */
	case HalfUp;

	/** To the nearest unit; exactly half goes to the even neighbor (banker's rounding). */
	case HalfEven;

	/** Toward zero: the fraction is dropped. */
	case Down;

	/** Away from zero: any fraction counts as a whole unit. */
	case Up;
}
