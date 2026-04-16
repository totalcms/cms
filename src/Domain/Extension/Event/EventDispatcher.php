<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Event;

use Psr\Log\LoggerInterface;

/**
 * Simple synchronous event dispatcher for extensions.
 *
 * Events are fired AFTER core operations succeed. If a listener throws,
 * the exception is caught and logged — it cannot affect the core operation.
 */
final class EventDispatcher
{
	/** @var array<string,list<array{callable, int}>> */
	private array $listeners = [];

	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Register a listener for an event.
	 *
	 * @param string   $event    Event name (e.g. 'object.created')
	 * @param callable $listener Receives array $payload
	 * @param int      $priority Lower = earlier (default 0)
	 */
	public function listen(string $event, callable $listener, int $priority = 0): void
	{
		$this->listeners[$event][] = [$listener, $priority];
	}

	/**
	 * Register all listeners from extensions.
	 *
	 * @param array<string,list<array{callable, int}>> $listeners
	 */
	public function registerAll(array $listeners): void
	{
		foreach ($listeners as $event => $eventListeners) {
			foreach ($eventListeners as $listener) {
				$this->listeners[$event][] = $listener;
			}
		}
	}

	/**
	 * Dispatch an event to all registered listeners.
	 *
	 * @param string              $event   Event name
	 * @param array<string,mixed> $payload Event data
	 */
	public function dispatch(string $event, array $payload = []): void
	{
		$listeners = $this->listeners[$event] ?? [];
		if ($listeners === []) {
			return;
		}

		// Sort by priority (lower = first)
		usort($listeners, fn (array $a, array $b): int => $a[1] <=> $b[1]);

		foreach ($listeners as [$listener, $priority]) {
			try {
				$listener($payload);
			} catch (\Throwable $e) {
				$this->logger->error("Event listener for '{$event}' threw an exception: {$e->getMessage()}", [
					'event'     => $event,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Check if any listeners are registered for an event.
	 */
	public function hasListeners(string $event): bool
	{
		return isset($this->listeners[$event]) && $this->listeners[$event] !== [];
	}
}
