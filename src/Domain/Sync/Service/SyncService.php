<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\OperationResult;

/**
 * Shared sync orchestration used by both the CLI and admin dashboard:
 * export → push, fetch → pull, and the diff between the two sides. The
 * HTTP leg is {@see SyncTransport}; payload shaping is {@see SyncPayloadFilter}.
 */
readonly class SyncService
{
	/** Upsert: the pushing side is authoritative. */
	private const ENDPOINT_MIRROR = '/api/sync/import';

	/** Skip-existing: the target keeps whatever it already has. */
	private const ENDPOINT_SEED = '/api/import/jumpstart';

	private SyncTransport $transport;
	private SyncPayloadFilter $filter;

	public function __construct(
		private JumpStartExporter $jumpStartExporter,
		private JumpStartImporter $jumpStartImporter,
		HttpClientInterface $httpClient,
		private BuilderTemplatePaths $paths,
		private SyncDiffService $diffService,
	) {
		$this->transport = new SyncTransport($httpClient);
		$this->filter    = new SyncPayloadFilter();
	}

	/**
	 * Compare local sync state against the remote's, item by item.
	 *
	 * One orchestration — export local, fetch remote, diff — shared by the
	 * CLI dry-runs and the admin Sync Manager, so every surface always
	 * reports the same statuses. See SyncDiffService for the semantics
	 * (content decides differs/same, timestamps only hint direction).
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 *
	 * @throws \RuntimeException When the remote cannot be reached or answers with an error
	 *
	 * @return array{schemas:array<string,mixed>,templates:array<string,mixed>,objects:array<string,mixed>,collections:array<string,mixed>}
	 */
	public function diff(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): array {
		$templateFilter = $this->syncableTemplateFilter($templateFilter);

		$this->jumpStartExporter->setMetadata('Sync Diff', 'Local state for sync comparison');
		$local  = $this->jumpStartExporter->exportSyncData($schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter)->toArray();
		$remote = $this->fetchRemoteSyncData($url, $key, $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);

		return $this->diffService->diff($local, $remote);
	}

	/**
	 * Git-managed projects deliver templates via git, not sync (Decision 8 of
	 * the git-first template workflow). Force the template filter to "none"
	 * ([]) so push/pull carry page records and content but never templates —
	 * each artifact keeps a single delivery channel.
	 *
	 * Public so the CLI dry-runs can apply the same rule to their LOCAL
	 * export: without it, a git-managed site's preview lists templates that
	 * a real push or pull would never move.
	 *
	 * @param list<string>|null $templateFilter
	 *
	 * @return list<string>|null
	 */
	public function syncableTemplateFilter(?array $templateFilter): ?array
	{
		return $this->paths->isProjectManaged() ? [] : $templateFilter;
	}

	/**
	 * Push schemas, templates, and objects from sync-allowlisted collections
	 * to a remote server.
	 *
	 * Schemas/templates use a flat tristate: null = all, [] = none, list = ids.
	 * Collections use a per-collection map because the UI presents each
	 * allowlisted collection (Pages, Mailer, Prompts, Dataviews) as its
	 * own section with its own all/specific/none picker over the contained
	 * object ids:
	 *   - null               → every object in every allowlisted collection
	 *   - ['id' => null]     → every object in that collection
	 *   - ['id' => [...ids]] → only those object ids
	 *   - key absent         → skip that collection
	 *
	 * Two endpoints, chosen by mode rather than by flag:
	 *
	 *   - `/api/sync/import` — upsert. The mirror path, and what every
	 *     push used before seeding existed. The pushing side is
	 *     authoritative.
	 *   - `/api/import/jumpstart` — skips objects that already exist on
	 *     the target. The seed path: `--objects` without `--overwrite`.
	 *
	 * Selecting by route preserves the existing contract ("the route's
	 * existence is the contract — no flag to forget") and has a useful
	 * side effect: both routes shipped in 3.5.0, so a seed push works
	 * against a production server that has not upgraded yet.
	 *
	 * The two modes are per-ITEM, not per-push, so a seeding push splits
	 * its payload and sends BOTH requests: everything that upserts to
	 * `/api/sync/import`, the seeded objects alone to
	 * `/api/import/jumpstart`. Picking one endpoint for the whole payload
	 * (as this first did) routed a mixed push like
	 * `push --schemas=faq --objects=faq` entirely through the skip-existing
	 * importer, which runs with upsert=false: pages already on the target
	 * were silently skipped, schemas were overwritten with no backup, and
	 * existing collections had their lifetime oid counter recomputed from
	 * the live index. Mixed payloads are the primary use case ("a new
	 * collection plus its starter rows"), so they must be split, not
	 * refused. `--overwrite` makes everything upsert again, so it stays a
	 * single request exactly as before.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 * @param array<string,list<string>|null>|null    $seedFilter
	 */
	public function push(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
		?array $seedFilter = null,
		bool $overwrite = false,
	): OperationResult {
		$templateFilter = $this->syncableTemplateFilter($templateFilter);

		$this->jumpStartExporter->setMetadata('Sync Push', 'Pushed via Total CMS sync');
		$jumpstart = $this->jumpStartExporter->exportSyncData(
			$schemaFilter,
			$templateFilter,
			$collectionsFilter,
			$collectionMetaFilter,
			$seedFilter,
		);

		if ($jumpstart->isEmpty()) {
			return OperationResult::success('Nothing to push — no matching schemas, templates, or collections found.', [
				'schemas'     => 0,
				'templates'   => 0,
				'collections' => 0,
			]);
		}

		// A seed push must not clobber what is already on the target, so its
		// objects go to the skip-existing route while everything else keeps
		// the mirror route. --overwrite makes the whole payload upsert again,
		// which is one request. See this method's docblock.
		if ($seedFilter === null || $overwrite) {
			return $this->pushMirrorOnly($url, $key, $jumpstart);
		}

		[$mirror, $seed] = $this->filter->splitSeeded($jumpstart, $seedFilter);

		return $this->pushSplit($url, $key, $mirror, $seed);
	}

	/**
	 * The single-request path: one upsert POST, exactly as every push worked
	 * before seeding existed.
	 */
	private function pushMirrorOnly(string $url, string $key, JumpStartData $jumpstart): OperationResult
	{
		$remoteResult = $this->transport->post($url, $key, self::ENDPOINT_MIRROR, $jumpstart);

		$counts = [
			'schemas'       => count($jumpstart->schemas),
			'templates'     => count($jumpstart->templates),
			'collections'   => SyncPayloadFilter::countCollections($jumpstart->collections),
			'objects'       => count($jumpstart->objects),
			'remote_result' => $remoteResult,
		];

		[$ok, $remoteErrors] = SyncTransport::verdict($remoteResult['success'] ?? true, $remoteResult['errors'] ?? null);
		if (!$ok) {
			return OperationResult::failure(
				'Push rejected by the remote.',
				$remoteErrors === [] ? null : implode('; ', $remoteErrors),
				$counts,
			);
		}

		return OperationResult::success('Push complete.', $counts);
	}

	/**
	 * The two-request path for a seeding push.
	 *
	 * Order matters: the mirror goes first so a schema or collection the
	 * seeded rows depend on exists on the target before they land. A failed
	 * mirror therefore cancels the seed rather than dropping rows into a
	 * half-built collection — and the result says so, because a partial push
	 * that reports as a clean one is exactly the failure mode this whole
	 * split exists to remove.
	 */
	private function pushSplit(string $url, string $key, JumpStartData $mirror, JumpStartData $seed): OperationResult
	{
		$errors       = [];
		$mirrorResult = [];
		$seedResult   = [];

		$mirrorState = 'was empty';
		$mirrorOk    = true;
		if (!$mirror->isEmpty()) {
			[$mirrorOk, $mirrorResult, $mirrorErrors] = $this->attemptPush($url, $key, self::ENDPOINT_MIRROR, $mirror);
			$mirrorState                              = $mirrorOk ? 'landed' : 'failed';
			foreach ($mirrorErrors as $error) {
				$errors[] = 'Mirror: ' . $error;
			}
		}

		$seedState = 'none to send';
		$seedOk    = true;
		if (!$seed->isEmpty()) {
			if (!$mirrorOk) {
				$seedOk    = false;
				$seedState = 'not sent (the mirror request failed first)';
			} else {
				[$seedOk, $seedResult, $seedErrors] = $this->attemptPush($url, $key, self::ENDPOINT_SEED, $seed);
				$seedState                          = $seedOk ? 'landed' : 'failed';
				foreach ($seedErrors as $error) {
					$errors[] = 'Seed: ' . $error;
				}
			}
		}

		$counts = [
			'schemas'       => count($mirror->schemas),
			'templates'     => count($mirror->templates),
			'collections'   => SyncPayloadFilter::countCollections($mirror->collections),
			'objects'       => count($mirror->objects) + count($seed->objects),
			'seeded'        => count($seed->objects),
			'remote_result' => $mirrorResult,
			'seed_result'   => $seedResult,
		];

		if ($mirrorOk && $seedOk) {
			return OperationResult::success('Push complete.', $counts);
		}

		return OperationResult::failure(
			sprintf('Push incomplete — mirror payload %s; seeded objects %s.', $mirrorState, $seedState),
			$errors === [] ? null : implode('; ', $errors),
			$counts,
		);
	}

	/**
	 * Send one leg of a split push and normalise every way it can fail —
	 * transport (a thrown RuntimeException) and per-item (a 200 whose body
	 * reports errors) — into the same tuple, so one failing leg can be
	 * reported alongside the other leg's outcome instead of unwinding the
	 * whole call.
	 *
	 * @return array{0:bool,1:array<string,mixed>,2:list<string>}
	 */
	private function attemptPush(string $url, string $key, string $endpoint, JumpStartData $payload): array
	{
		try {
			$result = $this->transport->post($url, $key, $endpoint, $payload);
		} catch (\RuntimeException $e) {
			return [false, [], [$e->getMessage()]];
		}

		[$ok, $errors] = SyncTransport::verdict($result['success'] ?? true, $result['errors'] ?? null);
		if (!$ok && $errors === []) {
			$errors = ['The remote reported the import as unsuccessful.'];
		}

		return [$ok, $result, $errors];
	}

	/**
	 * Fetch sync data from a remote server without importing.
	 * Used for dry-run previews.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 *
	 * @return array<string,mixed> Filtered JumpStart payload
	 */
	public function fetchRemoteSyncData(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): array {
		$payload = $this->transport->fetchExport($url, $key);

		return $this->filter->apply($payload, $schemaFilter, $this->syncableTemplateFilter($templateFilter), $collectionsFilter, $collectionMetaFilter);
	}

	/**
	 * Pull schemas, templates, and objects from sync-allowlisted collections
	 * from a remote server. See push() for the collectionsFilter map shape.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 */
	public function pull(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): OperationResult {
		$payload = $this->fetchRemoteSyncData($url, $key, $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);

		$counts = [
			'schemas'     => count($payload['schemas'] ?? []),
			'templates'   => count($payload['templates'] ?? []),
			// Counted from the block itself: a settings-only pull used to read
			// as "Nothing to pull" because collections were counted from objects.
			'collections' => SyncPayloadFilter::countCollections(is_array($payload['collections'] ?? null) ? $payload['collections'] : []),
			'objects'     => count($payload['objects'] ?? []),
		];

		if (array_sum($counts) === 0) {
			return OperationResult::success('Nothing to pull — no matching schemas, templates, or collections found.', $counts);
		}

		// Sync semantics: production is the source of truth on pull, so
		// existing local rows are overwritten rather than skipped — the same
		// authoritative-source rule as push, in the other direction. Pull is
		// initiated by the operator from their local shell (`tcms pull`), so
		// it carries shell trust and may apply code-executing system
		// collections mirrored down from production.
		$result                  = $this->jumpStartImporter->importFromDefinition($payload, true, allowSystemCollections: true);
		$counts['import_result'] = $result->toArray();

		// Same contract as push(): the importer reports per-item failures in its
		// own result rather than throwing.
		[$ok, $importErrors] = SyncTransport::verdict($result->success, $result->data['errors'] ?? null);
		if (!$ok) {
			return OperationResult::failure(
				'Pull completed with errors.',
				$importErrors === [] ? null : implode('; ', $importErrors),
				$counts,
			);
		}

		return OperationResult::success('Pull complete.', $counts);
	}
}
