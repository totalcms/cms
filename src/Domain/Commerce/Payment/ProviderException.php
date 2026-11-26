<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * A provider could not do what it was asked: its API refused, the network
 * failed, or the request was one it does not support.
 *
 * The message is for the log and the operator. It never holds a key, and it
 * is never shown to a buyer as it is.
 */
final class ProviderException extends \RuntimeException
{
	/** @param string $providerCode the provider's own error code, or the engine's (`total_mismatch`, `unsupported`) */
	public function __construct(string $message, public readonly string $providerCode = '', ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
