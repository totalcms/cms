<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension;

use League\Flysystem\Visibility;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Per-extension file storage rooted at
 * `<datadir>/.system/extension-data/{vendor}/{name}/`.
 *
 * This is THE sanctioned place for an extension to persist files (generated
 * secrets, caches, state). It lives under `.system`, so it is deny-all behind
 * the web server rules, excluded from version control, and survives
 * application updates. Files are written with private visibility (0600 files,
 * 0700 directories) — secret-grade by default, matching the OAuth key
 * convention.
 *
 * I/O goes through the shared StorageAdapterInterface like every other
 * datadir consumer. Note that Flysystem's own traversal protection only
 * guards the DATADIR root — a `../../` path would still resolve inside the
 * datadir (other extensions' secrets, collections, OAuth keys), so this class
 * enforces its own per-extension guard: relative paths only, no traversal.
 *
 * Extensions get an instance via `$context->storage()`. Using this API instead
 * of raw file functions also keeps the pre-enable source scan clean: the scan
 * flags `file_put_contents`/`fopen` in extension code as worth a human look,
 * precisely because writes outside this sanctioned area are unconstrained.
 */
final readonly class ExtensionStorage
{
	/**
	 * @param StorageAdapterInterface $storage      Datadir-rooted storage adapter
	 * @param string                  $datadir      Absolute datadir path (for path())
	 * @param string                  $relativeBase Extension storage dir, relative to datadir
	 */
	public function __construct(
		private StorageAdapterInterface $storage,
		private string $datadir,
		private string $relativeBase,
	) {
	}

	/**
	 * Absolute filesystem path for a file inside this extension's storage
	 * directory — for handing to libraries that need a real path (GD fonts,
	 * PDF generators, streaming). With no argument, returns the storage
	 * directory itself.
	 */
	public function path(string $relative = ''): string
	{
		if ($relative === '') {
			return $this->datadir . '/' . $this->relativeBase;
		}

		return $this->datadir . '/' . $this->location($relative);
	}

	/**
	 * Read a stored file. Returns null when the file does not exist.
	 */
	public function read(string $relative): ?string
	{
		$location = $this->location($relative);
		if (!$this->storage->fileExists($location)) {
			return null;
		}

		return $this->storage->read($location);
	}

	/**
	 * Write a file, creating parent directories as needed.
	 *
	 * @throws \League\Flysystem\FilesystemException When the write fails
	 *                                               (e.g. unwritable datadir).
	 *                                               Let it propagate from
	 *                                               register()/boot() — the
	 *                                               fault isolation layer
	 *                                               records it as a visible
	 *                                               extension error instead
	 *                                               of a silent no-op.
	 */
	public function write(string $relative, string $contents): void
	{
		$this->storage->flysystem()->write($this->location($relative), $contents, [
			'visibility'           => Visibility::PRIVATE,
			'directory_visibility' => Visibility::PRIVATE,
		]);
	}

	public function exists(string $relative): bool
	{
		return $this->storage->fileExists($this->location($relative));
	}

	/**
	 * Delete a stored file. Returns true when the file is gone afterwards
	 * (including when it never existed).
	 */
	public function delete(string $relative): bool
	{
		return $this->storage->delete($this->location($relative));
	}

	/**
	 * Datadir-relative location for an extension-relative path, with the
	 * traversal guard applied.
	 */
	private function location(string $relative): string
	{
		return $this->relativeBase . '/' . $this->guard($relative);
	}

	/**
	 * Reject absolute paths and directory traversal. Flysystem only rejects
	 * escaping the datadir root; this keeps extensions inside their OWN
	 * directory.
	 */
	private function guard(string $relative): string
	{
		$normalized = str_replace('\\', '/', $relative);

		if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
			throw new \InvalidArgumentException("Extension storage paths must be relative and traversal-free: {$relative}");
		}

		return $normalized;
	}
}
