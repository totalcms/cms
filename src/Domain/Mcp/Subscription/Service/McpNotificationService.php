<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Subscription\Service;

use Mcp\Server\Protocol;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Fans out notifications/resources/updated to all sessions subscribed to a
 * given URI. The actual SSE delivery is asynchronous — we append to each
 * subscriber's outgoing-queue file; their open SSE connection drains it on
 * its next loop iteration (~100ms latency).
 *
 * Per-session failures are caught and logged. The fan-out continues for the
 * remaining subscribers — one bad session must not block the others.
 *
 * Protocol design: Option A — one Protocol instance is injected and reused
 * across all sessions. Protocol::sendNotification() accepts the session as an
 * argument so all session-specific state flows through that parameter; the
 * Protocol itself holds no per-session state in its constructor.
 */
final readonly class McpNotificationService implements ResourceNotifier
{
	public function __construct(
		private SubscriptionIndex $index,
		// Typed against the SDK interface so the McpSubscriptionManager
		// implementation can stay `final` — tests mock the interface.
		private SubscriptionManagerInterface $subscriptionManager,
		private Protocol $protocol,
		private SessionManagerInterface $sessionManager,
		private LoggerInterface $logger = new NullLogger(),
	) {
	}

	/**
	 * Notify every session subscribed to $uri that the resource changed.
	 *
	 * Returns the number of sessions successfully notified.
	 */
	public function notifyResourceChanged(string $uri): int
	{
		$sent = 0;

		foreach ($this->index->subscribersOf($uri) as $sessionId) {
			try {
				$this->dispatchToSession($sessionId, $uri);
				$sent++;
			} catch (\Throwable $e) {
				$this->logger->error('McpNotificationService dispatch failed', [
					'session_id' => $sessionId,
					'uri'        => $uri,
					'exception'  => $e,
				]);
			}
		}

		return $sent;
	}

	/**
	 * Rehydrate one session by UUID string and push the notification.
	 *
	 * SessionManagerInterface::createWithId() reconstructs a Session backed by
	 * the store using a known UUID — this is the standard SDK rehydration path
	 * (same pattern used inside Protocol itself at lines ~411/430/472/499).
	 */
	private function dispatchToSession(string $sessionId, string $uri): void
	{
		$uuid    = Uuid::fromString($sessionId); // throws on malformed UUID
		$session = $this->sessionManager->createWithId($uuid);

		// Delegate to the T3 wrapper — it performs the isSubscribed() guard and
		// calls Protocol::sendNotification() with a ResourceUpdatedNotification.
		$this->subscriptionManager->notifyResourceChanged($this->protocol, $session, $uri);

		// Persist the in-memory queue mutation to disk. Protocol::sendNotification
		// internally calls $session->set(SESSION_OUTGOING_QUEUE, ...) but does
		// NOT save — the SDK's in-request flow saves later in the request
		// lifecycle. Our cross-request push has no such lifecycle hook, so we
		// persist explicitly here. Without this save the notification stays in
		// the local Session object's memory and is lost when the request ends.
		$session->save();
	}
}
