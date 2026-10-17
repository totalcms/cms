<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * Run a call whose failure the caller already handles, and keep the PHP
 * warning it raises to itself.
 *
 * `@` is not enough for that. It lowers error_reporting() for the call, but
 * every registered error handler still runs, and any handler that does not
 * check error_reporting() reports the warning anyway: PHPUnit's does, and so
 * would a log or Sentry handler written that way. A scoped handler is the one
 * thing that stops the warning at the source. The message comes back in
 * `$error`, which also replaces the error_clear_last() / error_get_last()
 * dance, whose process-wide state can report a stale error from earlier in
 * the request.
 */
final class QuietCall
{
	/**
	 * @template T
	 *
	 * @param callable(): T $call
	 *
	 * @param-out string|null $error the warning or notice the call raised, null when it raised none
	 *
	 * @return T
	 */
	public static function run(callable $call, ?string &$error = null): mixed
	{
		$error = null;
		set_error_handler(static function (int $errno, string $message) use (&$error): bool {
			$error = $message;

			return true;
		}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);

		try {
			return $call();
		} finally {
			restore_error_handler();
		}
	}
}
