<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceGate;

/**
 * Commerce switched on, whatever the test license says. The test app's
 * license is Pro with no paid product, so commerce tests call
 * `enableCommerce($app)`, which binds this.
 */
final class OpenCommerceGate implements CommerceGate
{
	public function allowed(): bool
	{
		return true;
	}

	public function status(): CommerceGateStatus
	{
		return CommerceGateStatus::Allowed;
	}
}
