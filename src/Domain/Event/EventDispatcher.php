<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Event\Payload\EventPayload;

/**
 * Simple synchronous event dispatcher for extensions.
 *
 * Events are fired AFTER core operations succeed. If a listener throws,
 * the exception is caught and logged — it cannot affect the core operation.
 */
final class EventDispatcher
{
	/**
	 * Events that are suppressed when a collection is mid-import. Listeners
	 * who care about import-time writes can subscribe to `import.created` /
	 * `import.updated` instead — those fire per-object during import.
	 */
	private const IMPORT_SUSPENDED_EVENTS = ['object.created', 'object.updated'];

	/** @var array<string,list<array{callable, int}>> */
	private array $listeners = [];

	/** @var array<string,bool> Collections currently mid-import — object.* events skip these */
	private array $suspendedImports = [];

	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Mark a collection as mid-import. While suspended, `object.created` and
	 * `object.updated` events for this collection are NOT dispatched —
	 * importers fire `import.created` / `import.updated` instead.
	 *
	 * Importers MUST pair this with {@see resumeForImport()} (typically via
	 * dispatching `import.completed` at the end of the batch, which the
	 * dispatcher catches and auto-resumes).
	 */
	public function suspendForImport(string $collection): void
	{
		$this->suspendedImports[$collection] = true;
	}

	/**
	 * Resume normal `object.*` event dispatching for a collection.
	 */
	public function resumeForImport(string $collection): void
	{
		unset($this->suspendedImports[$collection]);
	}

	/**
	 * Check if a collection is currently mid-import.
	 */
	public function isImportSuspended(string $collection): bool
	{
		return isset($this->suspendedImports[$collection]);
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
	 * @param string       $event   Event name
	 * @param EventPayload $payload Typed event payload
	 */
	public function dispatch(string $event, EventPayload $payload): void
	{
		// Convert typed payload to array for listener compatibility
		$payloadArray = $payload->toArray();

		// Skip object.created / object.updated for collections that are
		// mid-import — those write paths fire import.created / import.updated
		// instead so listeners can distinguish import-time writes from
		// user-driven ones.
		if (in_array($event, self::IMPORT_SUSPENDED_EVENTS, true)) {
			$collection = (string)($payloadArray['collection'] ?? '');
			if ($collection !== '' && isset($this->suspendedImports[$collection])) {
				return;
			}
		}

		// Auto-resume the suspension when the import lifecycle ends. This is
		// a safety net so a forgetful importer can't leave the dispatcher in
		// a permanently suspended state — even if the importer never calls
		// resumeForImport() explicitly, dispatching `import.completed` clears
		// the suspension. Fired BEFORE the listener pass so any listeners on
		// `import.completed` see a clean state.
		if ($event === 'import.completed') {
			$collection = (string)($payloadArray['collection'] ?? '');
			if ($collection !== '') {
				$this->resumeForImport($collection);
			}
		}

		$listeners = $this->listeners[$event] ?? [];
		if ($listeners === []) {
			return;
		}

		// Sort by priority (lower = first)
		usort($listeners, fn (array $a, array $b): int => $a[1] <=> $b[1]);

		foreach ($listeners as [$listener, $priority]) {
			try {
				$listener($payloadArray);
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
