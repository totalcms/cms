<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceGate;

/**
 * The gate of a site without the Commerce add-on, for tests that build a
 * piece of the admin by hand and need it to behave as most sites do.
 */
final class ClosedCommerceGate implements CommerceGate
{
	public function allowed(): bool
	{
		return false;
	}

	public function status(): CommerceGateStatus
	{
		return CommerceGateStatus::NeedsEntitlement;
	}
}
