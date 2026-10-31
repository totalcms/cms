<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;

interface SecurityCheck
{
	/**
	 * Run the check. Always returns at least one finding (`ok` when nothing is wrong).
	 *
	 * @return list<SecurityFinding>
	 */
	public function run(SecurityContext $context): array;

	/** True when the check sends HTTP requests to the site's own origin. */
	public function makesRequests(): bool;

	/** The check's finding id, also used when the check fails to run. */
	public function id(): string;

	/** A short human name for the check, shown when it fails to run. */
	public function title(): string;
}
