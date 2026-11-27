<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\Commerce\Payment\ProviderCapabilities;

/**
 * A provider that bills subscriptions from plans made ahead of time, and
 * says nothing about amounts a buyer names: like any provider that does not
 * declare `recurringCustomAmounts`.
 */
final class FixedPlanSubscriptionProvider extends FakeSubscriptionProvider
{
	public function __construct()
	{
		parent::__construct(true, false, 'fixed-sub');
	}

	public function capabilities(): ProviderCapabilities
	{
		return new ProviderCapabilities(subscriptions: true, tax: $this->tax);
	}
}
