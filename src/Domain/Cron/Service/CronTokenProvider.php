<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Cron\Service;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Owns the credential guarding the HTTP cron endpoints.
 *
 * A generated file rather than a setting: there is nothing for an operator to
 * choose, and `.system/` is already where this install keeps its generated
 * credentials (oauth-keys/, apikeys.json, oauth-clients.json).
 *
 * Created lazily on first read rather than by a setup command. `tcms oauth:setup`
 * is the cautionary precedent — "ungenerated OAuth keys" became a documented
 * troubleshooting symptom precisely because it was possible to skip.
 *
 * token() and tokenOrCreate() are asymmetric on purpose: the middleware reads on
 * every request, including unauthenticated probes, and a read must never be a
 * way to make the server write a file.
 */
final readonly class CronTokenProvider
{
	private const TOKEN_FILE = '.system/cron-token';

	public function __construct(
		private Config $config,
	) {
	}

	/** The stored token, or null when none has been generated. Never writes. */
	public function token(): ?string
	{
		$path = $this->path();

		if (!is_file($path)) {
			return null;
		}

		$raw = @file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$token = trim($raw);

		// An empty or truncated file must not authenticate an empty `?token=`.
		return $token === '' ? null : $token;
	}

	/** The stored token, generating and persisting one when absent. */
	public function tokenOrCreate(): string
	{
		return $this->token() ?? $this->write($this->generate());
	}

	/** Replace the token, invalidating every cron URL already in use. */
	public function regenerate(): string
	{
		return $this->write($this->generate());
	}

	private function generate(): string
	{
		return bin2hex(random_bytes(24));
	}

	private function write(string $token): string
	{
		$path = $this->path();
		$dir  = dirname($path);

		if (!is_dir($dir)) {
			mkdir($dir, 0700, true);
		}

		// Temp-then-rename so a concurrent reader never sees a half-written file,
		// matching OAuthClientRepository's atomic-write pattern.
		$temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		file_put_contents($temp, $token);
		chmod($temp, 0600);
		rename($temp, $path);

		return $token;
	}

	private function path(): string
	{
		return PathUtils::absolutePath($this->config->datadir, self::TOKEN_FILE);
	}
}
