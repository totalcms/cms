<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Data;

enum Severity: string
{
	case Critical = 'critical';
	case Warning  = 'warning';
	case Info     = 'info';

	/** Sort rank: lower is more severe. */
	public function rank(): int
	{
		return match ($this) {
			self::Critical => 0,
			self::Warning  => 1,
			self::Info     => 2,
		};
	}
}
