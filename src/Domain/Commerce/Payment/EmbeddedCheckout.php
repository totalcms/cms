<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * What the store's pay page needs to mount a provider's checkout in itself.
 */
final readonly class EmbeddedCheckout
{
	/** @param array<string,string> $config passed to the module's mount(); never a secret key */
	public function __construct(
		public string $moduleUrl,
		public array $config,
	) {
	}
}
