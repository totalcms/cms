<?php

namespace TotalCMS\Domain\License\Data;

/**
 * License edition levels with hierarchy support.
 * Lite < Standard < Pro.
 */
enum Edition: string
{
	case LITE        = 'lite';
	case STANDARD    = 'standard';
	case PRO         = 'pro';
	case ENTERPRISE  = 'enterprise';
	case DEVELOPMENT = 'development';
	case TRIAL       = 'trial';
	case UNKNOWN     = 'unknown';

	/**
	 * Get the hierarchy level for this edition.
	 * Higher levels include all features from lower levels.
	 */
	public function level(): int
	{
		return match ($this) {
			self::UNKNOWN  => 0,
			self::LITE     => 1,
			self::STANDARD => 2,
			self::PRO, self::ENTERPRISE, self::DEVELOPMENT, self::TRIAL => 3,
		};
	}

	/**
	 * Check if this edition includes at least the given level.
	 */
	public function hasLevel(int $requiredLevel): bool
	{
		return $this->level() >= $requiredLevel;
	}

	/**
	 * Create an Edition from a string value, defaulting to UNKNOWN if invalid.
	 */
	public static function fromString(string $value): self
	{
		return self::tryFrom(strtolower($value)) ?? self::UNKNOWN;
	}
}
