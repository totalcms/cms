<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Money\Money;
use TotalCMS\Support\Config;

/**
 * The Twig `money` filter: an amount held in minor units, as orders hold
 * every amount, written for a person. `{{ 1999|money('USD') }}` is "$19.99".
 *
 * The currency is the store's when none is named, and the format is the
 * site's locale. Anything that is not a whole number prints nothing, so a
 * page showing a field an order does not have never breaks.
 */
final readonly class MoneyFilter
{
	public function __construct(
		private MoneyFormatter $formatter,
		private CommerceSettings $settings,
		private Config $config,
	) {
	}

	public function __invoke(mixed $amount, ?string $currency = null): string
	{
		$minor = is_int($amount) ? $amount : null;
		if (is_string($amount) && preg_match('/^-?\d{1,18}\z/', $amount) === 1) {
			$minor = (int)$amount;
		}
		if ($minor === null) {
			return '';
		}

		try {
			// Read when asked: the locale and the currency are settings.
			return $this->formatter->format(Money::of($minor, $currency ?? $this->settings->currency()), $this->config->locale);
		} catch (\InvalidArgumentException) {
			return '';
		}
	}
}
