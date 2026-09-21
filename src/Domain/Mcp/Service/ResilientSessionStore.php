<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Wraps an MCP session store so a corrupt payload can't wedge a client.
 *
 * `Mcp\Server\Session\Session::readData()` decodes whatever `read()` returns
 * with `JSON_THROW_ON_ERROR`, so a single unparseable session file turns into a
 * JsonException on EVERY subsequent request carrying that session id — the
 * client is locked out until someone deletes the file by hand.
 *
 * Two things produce those files:
 *
 *   1. `FileSessionStore::write()` stages through one fixed `{uuid}.tmp` path.
 *      `file_put_contents(..., LOCK_EX)` truncates the file before it takes the
 *      lock, so when two requests on the same session write concurrently — the
 *      normal shape for an MCP client that pipelines calls — the second writer
 *      can blank the staging file out from under the first, which then renames
 *      the truncated result into place.
 *   2. Its cross-device fallback copies into the live path in the open, where a
 *      concurrent reader sees a half-written file.
 *
 * So this decorator stages each write under a unique temp name of its own, and
 * treats an undecodable read as a miss. A missing session is a shape the
 * protocol already handles — the client re-initializes and carries on — which
 * is strictly better than a permanent 500.
 */
final readonly class ResilientSessionStore implements SessionStoreInterface
{
	public function __construct(
		private SessionStoreInterface $inner,
		private string $directory,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function exists(Uuid $id): bool
	{
		return $this->inner->exists($id);
	}

	public function read(Uuid $id): string|false
	{
		$data = $this->inner->read($id);

		if ($data === false) {
			return false;
		}

		try {
			json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			// Drop the unreadable file so the next request starts clean rather
			// than re-reading the same corruption forever.
			$this->logger?->warning('Discarding corrupt MCP session', [
				'session' => $id->toRfc4122(),
				'error'   => $exception->getMessage(),
			]);
			$this->inner->destroy($id);

			return false;
		}

		return $data;
	}

	public function write(Uuid $id, string $data): bool
	{
		$path = $this->directory . '/' . $id->toRfc4122();
		$tmp  = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

		if (@file_put_contents($tmp, $data) === false) {
			// Can't stage our own write — let the inner store try its way.
			return $this->inner->write($id, $data);
		}

		if (!@rename($tmp, $path)) {
			@unlink($tmp);

			return $this->inner->write($id, $data);
		}

		@touch($path);

		return true;
	}

	public function destroy(Uuid $id): bool
	{
		return $this->inner->destroy($id);
	}

	/** @return Uuid[] */
	public function gc(): array
	{
		// Sweep staging files an interrupted write may have orphaned. They are
		// invisible to the inner store's own gc(), which only knows the bare
		// uuid filenames.
		foreach (glob($this->directory . '/*.tmp') ?: [] as $stale) {
			if (time() - (@filemtime($stale) ?: 0) > 3600) {
				@unlink($stale);
			}
		}

		return $this->inner->gc();
	}
}
