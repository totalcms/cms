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
		'automations',
	];

	/**
	 * The five feature-named CLI flags and the collection each one moves.
	 *
	 * These collections are site machinery the developer authors — Pages,
	 * Data Views, Mailer templates, MCP prompts, Automations. The operator
	 * knows them by their admin labels, not by collection id, which is why
	 * the flags are named for the feature and not for the storage. It also
	 * spares anyone having to know that the collection is `builder-pages`
	 * while the schema is `builder-page`.
	 *
	 * Values must stay in lockstep with IDS — SyncableCollectionsTest fails
	 * if they drift. IDS cannot be derived from this map because a PHP
	 * constant expression cannot call array_values().
	 *
	 * @var array<string,string>
	 */
	public const FEATURE_FLAGS = [
		'pages'       => 'builder-pages',
		'dataviews'   => 'dataviews',
		'mailer'      => 'mailer',
		'mcp-prompts' => 'mcp-prompt',
		'automations' => 'automations',
	];

	/**
	 * Collections that `--objects` refuses to seed.
	 *
	 * `playground` is a per-install scratchpad, created on demand by
	 * whichever environment someone happens to open the Twig Playground on.
	 * It is already excluded from sync elsewhere for the same reason.
	 *
	 * The other four are binary-only. Everywhere else an omitted binary
	 * field is one field among many and the record still carries its title
	 * and body, but in these collections the binary IS the object —
	 * processObjectData() strips it, so seeding would land a record
	 * pointing at a file the target does not have. Refusing beats shipping
	 * an empty husk.
	 *
	 * The five in FEATURE_FLAGS are excluded by seedable() rather than
	 * listed here: they are reachable, just through their own flag.
	 *
	 * @var list<string>
	 */
	public const SEED_EXCLUDED = [
		'playground',
		'image',
		'gallery',
		'file',
		'depot',
	];

	public static function contains(string $id): bool
	{
		return in_array($id, self::IDS, true);
	}

	/**
	 * Whether `--objects` may seed this collection.
	 *
	 * Unlike contains(), this is permissive by default: seeding cannot
	 * overwrite anything (the receiving endpoint skips existing objects),
	 * so the guard only has to stop collections where seeding is
	 * meaningless or actively misleading.
	 */
	public static function seedable(string $id): bool
	{
		return !in_array($id, self::SEED_EXCLUDED, true)
			&& !in_array($id, self::FEATURE_FLAGS, true);
	}

	/**
	 * Seedable, but deliberately never offered in the Sync Manager.
	 *
	 * `auth` is the whole list. Password hashes are stripped from every sync
	 * payload, so a seeded user arrives as an account nobody can sign into.
	 * That is a defensible expert action behind `tcms push --objects=auth`,
	 * where the operator typed the collection's name. It is a trap as a
	 * checkbox sitting between Blog and FAQ, where one careless tick creates
	 * broken accounts on a live site.
	 *
	 * This is the one place the UI narrows what the CLI allows, so it lives
	 * beside the other carve-outs rather than in the admin action.
	 *
	 * @var list<string>
	 */
	public const UI_SEED_EXCLUDED = ['auth'];

	/** Whether the Sync Manager may offer this collection for seeding. */
	public static function seedableInUi(string $id): bool
	{
		return self::seedable($id) && !in_array($id, self::UI_SEED_EXCLUDED, true);
	}

	/** The feature flag that owns this collection, if any. */
	public static function flagFor(string $collectionId): ?string
	{
		$flag = array_search($collectionId, self::FEATURE_FLAGS, true);

		return $flag === false ? null : $flag;
	}
}
