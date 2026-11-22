<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;

/**
 * Whether this site may use commerce.
 *
 * Commerce is not an edition. It is a paid add-on on top of Pro, so one
 * question has two parts, and everything that gates commerce asks it here:
 * the route middleware, the payment providers, the Twig API.
 */
interface CommerceGate
{
	public function allowed(): bool;

	public function status(): CommerceGateStatus;
}
