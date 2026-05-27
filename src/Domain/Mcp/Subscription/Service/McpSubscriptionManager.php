<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * MCP subscription manager that mirrors the SDK's SessionSubscriptionManager
 * for per-session storage AND maintains a reverse index (URI → session IDs)
 * via SubscriptionIndex.
 *
 * The SDK's built-in isSubscribed() + notifyResourceChanged() paths work
 * identically to the default implementation.  The extra side-effect of
 * subscribe() / unsubscribe() is writing to the SubscriptionIndex so that T3's
 * event listener (Phase 2 Chunk B4) can query "all sessions subscribed to
 * tcms://blog/" after a blog object changes.
 */
final readonly class McpSubscriptionManager implements SubscriptionManagerInterface
{
	public function __construct(
		private SubscriptionIndex $index,
		private LoggerInterface $logger = new NullLogger(),
	) {
	}

	// -------------------------------------------------------------------------
	// SubscriptionManagerInterface
	// -------------------------------------------------------------------------

	/**
	 * @throws InvalidArgumentException
	 */
	public function subscribe(SessionInterface $session, string $uri): void
	{
		// Mirror the SDK default: write into the session's resource_subscriptions map.
		/** @var array<string, true> $subscriptions */
		$subscriptions        = $session->get('resource_subscriptions', []);
		$subscriptions[$uri]  = true;
		$session->set('resource_subscriptions', $subscriptions);
		$session->save();

		// Also write to the reverse index so cross-session lookup is possible.
		// On failure (e.g. filesystem full), log loudly but don't propagate — the
		// session is already subscribed and the SDK's per-session paths will keep
		// working; the only consequence is that the cross-session listener may
		// miss this subscriber until the index recovers.
		$this->writeIndex(
			fn () => $this->index->add($uri, $session->getId()->toRfc4122()),
			'add',
			$uri,
			$session,
		);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function unsubscribe(SessionInterface $session, string $uri): void
	{
		// Mirror the SDK default: remove from the session map.
		/** @var array<string, true> $subscriptions */
		$subscriptions = $session->get('resource_subscriptions', []);
		unset($subscriptions[$uri]);
		$session->set('resource_subscriptions', $subscriptions);
		$session->save();

		// Remove from reverse index. Same failure-mode policy as subscribe().
		$this->writeIndex(
			fn () => $this->index->remove($uri, $session->getId()->toRfc4122()),
			'remove',
			$uri,
			$session,
		);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function isSubscribed(SessionInterface $session, string $uri): bool
	{
		// Read from the session only — identical to SDK default.
		// The reverse index is NOT consulted here because this method is on the
		// hot-path during SDK request handling and the session is the canonical
		// per-session truth.
		/** @var array<string, true> $subscriptions */
		$subscriptions = $session->get('resource_subscriptions', []);

		return isset($subscriptions[$uri]);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function notifyResourceChanged(Protocol $protocol, SessionInterface $session, string $uri): void
	{
		// Identical to the SDK default — per-session check + send.
		if (!$this->isSubscribed($session, $uri)) {
			return;
		}

		try {
			$protocol->sendNotification(
				new ResourceUpdatedNotification($uri),
				$session
			);
		} catch (InvalidArgumentException $e) {
			$this->logger->error('Error sending resource notification to session', [
				'session_id' => $session->getId()->toRfc4122(),
				'uri'        => $uri,
				'exception'  => $e,
			]);

			throw $e;
		}
	}

	private function writeIndex(callable $op, string $action, string $uri, SessionInterface $session): void
	{
		try {
			$op();
		} catch (\Throwable $e) {
			$this->logger->error('McpSubscriptionManager index write failed', [
				'action'     => $action,
				'uri'        => $uri,
				'session_id' => $session->getId()->toRfc4122(),
				'exception'  => $e,
			]);
		}
	}
}
