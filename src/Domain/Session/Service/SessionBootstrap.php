<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Session\Service;

use Odan\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * What the PHP API does before PhpSession starts when the host page already
 * opened a session of its own (a Stacks page, another PHP app): the existing
 * session is closed so PhpSession can start cleanly, and — per
 * `session.conflictStrategy` — its data is carried over or dropped.
 */
readonly class SessionBootstrap
{
	public const PRESERVE = 'preserve';
	public const REPLACE  = 'replace';

	public function __construct(private LoggerInterface $logger)
	{
	}

	/**
	 * Close a session the host already opened and return what should be
	 * restored into ours. Nothing to do when no session is active.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,mixed>
	 */
	public function resolveConflict(string $strategy): array
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return [];
		}

		$existing = $_SESSION ?? [];

		$this->logger->debug('Session conflict detected', [
			'strategy'     => $strategy,
			'existingKeys' => array_keys($existing),
		]);

		session_destroy();

		return self::carriedOver($strategy, $existing);
	}

	/**
	 * The host's session data that survives the restart: all of it under
	 * `preserve` (the default), none under `replace` or anything unknown.
	 *
	 * @param array<string,mixed> $existing
	 *
	 * @return array<string,mixed>
	 */
	public static function carriedOver(string $strategy, array $existing): array
	{
		return $strategy === self::PRESERVE ? $existing : [];
	}

	/**
	 * Put the host's data back under its original keys. Total CMS uses
	 * namespaced keys of its own, so nothing collides.
	 *
	 * @param array<string,mixed> $data
	 */
	public function restore(SessionInterface $session, array $data): void
	{
		foreach ($data as $key => $value) {
			$session->set($key, $value);
		}

		$this->logger->debug('Session data restored', ['restoredKeys' => array_keys($data)]);
	}
}
