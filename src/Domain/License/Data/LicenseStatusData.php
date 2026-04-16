<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Data;

/**
 * License status data for sidebar display.
 */
readonly class LicenseStatusData
{
	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(
		public bool $showIcon      = false,
		public string $severity    = 'info',   // 'info', 'warning', 'error'
		public ?int $daysRemaining = null,
		public string $tooltip     = '',
	) {
	}
}
