<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Session\Service;

use Odan\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * What the PHP API does before PhpSession starts when the host page already
 * opened a session of its own (a Stacks page, another PHP app): the existing
 * session is closed so PhpSession can start cleanly, and — per
 * `session.conflictStrategy` — its data is carried over or dropped.
 *
 * Closed, never destroyed. The host usually opened its session in PHP's
 * default folder while ours lives in <datadir>/.system/sessions, and the
 * cookie id is the same in both. Another app opening the session with
 * use_strict_mode on rejects an id that has no file behind it and mints a
 * new one — which orphans our session and logs the visitor out on the next
 * page. Leaving the host's file where it was keeps every store agreeing on
 * the id; see docs/operations/sharing-sessions.
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
	 * `$savePath` is where our session is about to live. When that differs
	 * from the folder the host opened its session in, the two apps have two
	 * stores for one cookie and anything written on this page never reaches
	 * the other — worth an info line naming both paths, since nothing else
	 * shows it. A conflict inside one folder is routine and stays at debug.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,mixed>
	 */
	public function resolveConflict(string $strategy, string $savePath = ''): array
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return [];
		}

		$existing = $_SESSION ?? [];
		$hostPath = self::hostSavePath();
		$carried  = self::carriedOver($strategy, $existing);

		// Under replace the host's data is dropped. Destroying the session
		// used to leave no file; closing it leaves an empty one, which is the
		// same outcome for the host and keeps the id valid for strict-mode
		// readers.
		if ($strategy !== self::PRESERVE) {
			$_SESSION = [];
		}
		session_write_close();

		$relocated = $savePath !== '' && self::normalizePath($hostPath) !== self::normalizePath($savePath);
		$this->logger->log(
			$relocated ? LogLevel::INFO : LogLevel::DEBUG,
			$relocated ? 'Session relocated from the host\'s store; the host app will not see this page\'s writes' : 'Session conflict detected',
			[
				'strategy'     => $strategy,
				'existingKeys' => array_keys($existing),
				'hostPath'     => $hostPath,
				'savePath'     => $savePath,
			],
		);

		return $carried;
	}

	/**
	 * The folder the active session's file is in: `session.save_path`, minus
	 * the optional `N;` depth and `N;mode;` prefixes the files handler
	 * accepts, or the system temp directory when the setting is empty, which
	 * is where the files handler falls back to.
	 */
	public static function hostSavePath(): string
	{
		$path = (string)session_save_path();
		if ($path === '') {
			return sys_get_temp_dir();
		}
		$semicolon = strrpos($path, ';');

		return $semicolon === false ? $path : substr($path, $semicolon + 1);
	}

	private static function normalizePath(string $path): string
	{
		$real = realpath($path);

		return rtrim($real === false ? $path : $real, '/');
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
