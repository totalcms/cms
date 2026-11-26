<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * Sends a provider's event to the part of the store that handles its kind:
 * a payment event to the payment processor, a subscription event to the
 * subscription processor.
 */
final class ProviderEventRouter
{
	/** @var array<class-string<ProviderEvent>,callable(ProviderEvent): void> */
	private array $handlers = [];

	/**
	 * @template T of ProviderEvent
	 *
	 * @param class-string<T>   $eventClass
	 * @param callable(T): void $handler    throws when the event could not be handled, so it is sent again
	 */
	public function handle(string $eventClass, callable $handler): void
	{
		/** @var callable(ProviderEvent): void $handler */
		$this->handlers[$eventClass] = $handler;
	}

	/** @return bool false when nothing handles this kind of event */
	public function route(ProviderEvent $event): bool
	{
		foreach ($this->handlers as $class => $handler) {
			if ($event instanceof $class) {
				$handler($event);

				return true;
			}
		}

		return false;
	}
}
