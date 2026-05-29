<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Data;

/**
 * Allowlist of reserved collections whose objects sync push/pull can move.
 *
 * Hardcoded by design. The moment we expose this as a config seam, operators
 * will add file-bearing collections (image/file/depot/gallery) and assume
 * binaries travel — we don't ship binary sync, and pretending we do would be
 * worse than refusing. Custom config-shaped collections (e.g. an agency's
 * own "site-settings" collection) need a follow-up release that designs the
 * binary-handling story first.
 *
 * Used by:
 *   - JumpStartExporter::exportSyncData() — the export-time filter
 *   - JumpStartExporter::exportSyncCollectionObjects() — secondary guard that
 *     strips any non-allowlist ID even if a malformed filter reaches it
 *   - AdminUtilsAction — populates the UI's Collections section
 */
final class SyncableCollections
{
	/** @var list<string> */
	public const IDS = [
		'builder-pages',
		'mailer',
		'mcp-prompt',
		'dataviews',
	];

	public static function contains(string $id): bool
	{
		return in_array($id, self::IDS, true);
	}
}
