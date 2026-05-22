<?php

declare(strict_types=1);

use Symfony\Component\Uid\Uuid;
use TotalCMS\Domain\Event\Listener\McpResourceSubscriptionListener;
use TotalCMS\Domain\Mcp\Subscription\Service\SubscriptionIndex;

/**
 * Cross-request push integration test for the MCP subscription chain:
 *
 *     T3 object.* event → McpResourceSubscriptionListener
 *                       → McpNotificationService → SubscriptionIndex lookup
 *                       → Protocol::sendNotification → session.outgoing_queue
 *
 * The test invokes the listener DIRECTLY rather than through EventDispatcher
 * so the CollectionMetadataListener -> collection.updated -> McpSessionListener
 * -> McpSessionInvalidator chain (which wipes /mcp-sessions/ on every object
 * write) doesn't delete the seeded subscriber's session file mid-test. That
 * cascade is a pre-existing tool-surface-invalidation behaviour from Phase 1 —
 * out of scope to redesign here. The unit-level listener tests cover the
 * coalescing + per-event semantics; this file covers the cross-process push
 * path that exercises the listener → notifier → SDK → session-file outbox
 * pipeline end to end.
 */
beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	$this->setUpApp(bootstrap());

	$container         = $this->app->getContainer();
	$collectionFetcher = $container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
	$collectionFetcher->fetchOrCreateReserved('blog');

	// Clean the subscription index file so each test starts from an empty
	// reverse index. SubscriptionIndex lives at {tmpdir}/mcp-subscriptions.json
	// (outside /mcp-sessions/ which gets wiped by McpSessionInvalidator).
	$indexFile = $container->get(TotalCMS\Support\Config::class)->tmpdir . '/mcp-subscriptions.json';
	if (file_exists($indexFile)) {
		@unlink($indexFile);
	}
});

/**
 * Seed a subscriber: write a session file on disk with the URI already in its
 * resource_subscriptions map, AND register the session id in the
 * SubscriptionIndex. Mirrors what McpSubscriptionManager::subscribe would do
 * if a client had subscribed via the MCP protocol.
 */
function seedSubscriber(Slim\App $app, string $uri): string
{
	$container = $app->getContainer();
	$config    = $container->get(TotalCMS\Support\Config::class);
	$dir       = $config->tmpdir . '/mcp-sessions';
	if (!is_dir($dir)) {
		mkdir($dir, 0755, true);
	}

	$uuid      = Uuid::v7();
	$sessionId = $uuid->toRfc4122();

	$sessionPayload = [
		'initialized'            => true,
		'protocol_version'       => '2025-06-18',
		'resource_subscriptions' => [$uri => true],
	];
	file_put_contents($dir . '/' . $sessionId, json_encode($sessionPayload));

	$container->get(SubscriptionIndex::class)->add($uri, $sessionId);

	return $sessionId;
}

/**
 * Return any queued notifications/resources/updated entries targeting $uri
 * inside the named session's outgoing queue.
 *
 * @return list<array<string,mixed>>
 */
function readQueuedNotifications(string $tmpdir, string $sessionId, string $uri): array
{
	$file = $tmpdir . '/mcp-sessions/' . $sessionId;
	if (!file_exists($file)) {
		return [];
	}

	$decoded = json_decode((string)file_get_contents($file), true);
	if (!is_array($decoded)) {
		return [];
	}

	// Session::set('_mcp.outgoing_queue', …) uses dot notation, so the on-disk
	// JSON is nested: { "_mcp": { "outgoing_queue": [ … ] } }.
	$queue = $decoded['_mcp']['outgoing_queue'] ?? [];
	if (!is_array($queue)) {
		return [];
	}

	$matches = [];
	foreach ($queue as $entry) {
		// Each entry is { "message": "<json string>", "context": {...} }
		$encoded = is_array($entry) ? ($entry['message'] ?? null) : null;
		$msg     = is_string($encoded) ? json_decode($encoded, true) : null;
		if (!is_array($msg)) {
			continue;
		}
		if (($msg['method'] ?? null) !== 'notifications/resources/updated') {
			continue;
		}
		if (($msg['params']['uri'] ?? null) === $uri) {
			$matches[] = $msg;
		}
	}

	return $matches;
}

describe('McpSubscriptions — cross-request push', function (): void {
	it('writes notifications/resources/updated into the subscriber session outbox', function (): void {
		$sessionId = seedSubscriber($this->app, 'tcms://blog/');

		$listener = $this->app->getContainer()->get(McpResourceSubscriptionListener::class);
		$listener->onObjectCreated(['collection' => 'blog', 'id' => 'hello-world']);

		$tmpdir = $this->app->getContainer()->get(TotalCMS\Support\Config::class)->tmpdir;
		$queued = readQueuedNotifications($tmpdir, $sessionId, 'tcms://blog/');
		expect($queued)->not->toBeEmpty();
		expect($queued[0]['method'])->toBe('notifications/resources/updated');
		expect($queued[0]['params']['uri'])->toBe('tcms://blog/');
	});

	it('does not notify subscribers of unrelated collections', function (): void {
		$blogSession    = seedSubscriber($this->app, 'tcms://blog/');
		$productSession = seedSubscriber($this->app, 'tcms://products/');

		$listener = $this->app->getContainer()->get(McpResourceSubscriptionListener::class);
		$listener->onObjectCreated(['collection' => 'blog', 'id' => 'p1']);

		$tmpdir = $this->app->getContainer()->get(TotalCMS\Support\Config::class)->tmpdir;
		expect(readQueuedNotifications($tmpdir, $blogSession, 'tcms://blog/'))->not->toBeEmpty();
		expect(readQueuedNotifications($tmpdir, $productSession, 'tcms://products/'))->toBe([]);
	});

	it('fans out to multiple subscribers of the same uri', function (): void {
		$s1 = seedSubscriber($this->app, 'tcms://blog/');
		$s2 = seedSubscriber($this->app, 'tcms://blog/');

		$listener = $this->app->getContainer()->get(McpResourceSubscriptionListener::class);
		$listener->onObjectCreated(['collection' => 'blog', 'id' => 'p1']);

		$tmpdir = $this->app->getContainer()->get(TotalCMS\Support\Config::class)->tmpdir;
		expect(readQueuedNotifications($tmpdir, $s1, 'tcms://blog/'))->not->toBeEmpty();
		expect(readQueuedNotifications($tmpdir, $s2, 'tcms://blog/'))->not->toBeEmpty();
	});

	it('coalesces back-to-back events on the same uri within one request', function (): void {
		$sessionId = seedSubscriber($this->app, 'tcms://blog/');

		$listener = $this->app->getContainer()->get(McpResourceSubscriptionListener::class);
		// Same listener instance receives three back-to-back events — the
		// in-memory per-(uri, instance) coalescing collapses them to one notify.
		$listener->onObjectCreated(['collection' => 'blog', 'id' => 'a']);
		$listener->onObjectUpdated(['collection' => 'blog', 'id' => 'a']);
		$listener->onObjectDeleted(['collection' => 'blog', 'id' => 'a']);

		$tmpdir = $this->app->getContainer()->get(TotalCMS\Support\Config::class)->tmpdir;
		$queued = readQueuedNotifications($tmpdir, $sessionId, 'tcms://blog/');
		expect(count($queued))->toBe(1);
	});
});
