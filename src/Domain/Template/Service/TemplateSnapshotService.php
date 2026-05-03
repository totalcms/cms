<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Template\Repository\TemplateSnapshotRepository;

/**
 * Per-template version history. Captures the old content of a template
 * each time it's saved so users can browse and restore prior versions
 * without needing git.
 *
 * Storage I/O lives in {@see TemplateSnapshotRepository}; this service owns
 * the capture sequencing, retention policy, and ordering.
 */
readonly class TemplateSnapshotService
{
	public const MAX_VERSIONS = 50;

	public function __construct(private TemplateSnapshotRepository $repository)
	{
	}

	/**
	 * Capture a snapshot of $contents for the template at id+folder.
	 * Skips the write when contents are empty (nothing to preserve) or when
	 * the template path is invalid (capture is a side-effect of saving — we
	 * don't want a malformed path to fail the save).
	 */
	public function capture(string $id, ?string $folder, string $contents): void
	{
		if ($contents === '') {
			return;
		}

		try {
			$timestamp = time();
			// If a snapshot at this exact timestamp already exists (rapid
			// double-save), nudge forward to keep ordering monotonic.
			while ($this->repository->exists($id, $folder, $timestamp)) {
				$timestamp++;
			}

			$this->repository->write($id, $folder, $timestamp, $contents);
			$this->prune($id, $folder);
		} catch (\InvalidArgumentException) {
			// Invalid template path — skip silently.
		}
	}

	/**
	 * List snapshot timestamps for a template, newest first.
	 *
	 * @return list<int>
	 */
	public function listVersions(string $id, ?string $folder = null): array
	{
		try {
			$timestamps = $this->repository->listTimestamps($id, $folder);
		} catch (\InvalidArgumentException) {
			return [];
		}

		rsort($timestamps);

		return $timestamps;
	}

	/**
	 * Read the contents of a specific snapshot.
	 *
	 * @throws \DomainException When the path is invalid or the snapshot is missing
	 */
	public function readVersion(string $id, ?string $folder, int $timestamp): string
	{
		try {
			$contents = $this->repository->read($id, $folder, $timestamp);
		} catch (\InvalidArgumentException) {
			throw new \DomainException('Invalid template path');
		}

		if ($contents === null) {
			throw new \DomainException("Snapshot not found: $timestamp");
		}

		return $contents;
	}

	/**
	 * Keep the MAX_VERSIONS newest snapshots, delete the rest.
	 */
	private function prune(string $id, ?string $folder): void
	{
		$timestamps = $this->repository->listTimestamps($id, $folder);
		if (count($timestamps) <= self::MAX_VERSIONS) {
			return;
		}

		rsort($timestamps);
		foreach (array_slice($timestamps, self::MAX_VERSIONS) as $ts) {
			$this->repository->delete($id, $folder, $ts);
		}
	}
}
