<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Sync\Data\SyncableCollections;

/**
 * Shared filter handling for `tcms push` and `tcms pull`.
 *
 * The two commands are mirror images of each other, and their filter
 * semantics have to agree exactly — a difference between what push sends
 * and what pull would fetch is precisely the kind of surprise sync exists
 * to eliminate. Everything both sides share lives here.
 */
trait SyncFilterOptions
{
	/**
	 * Options shared by `push` and `pull`.
	 *
	 * The five feature flags replace the old `--collections`, which named a
	 * gated set of collection ids and leaked storage detail: to the operator
	 * those five are Pages, Data Views, Mailer templates, MCP prompts and
	 * Automations — features, not collections. `--collections` is now the
	 * collection SETTINGS flag (formerly `--collection-meta`), which is what
	 * the name should always have meant.
	 */
	private function addSyncFilterOptions(string $verb): void
	{
		$this
			->addOption('schemas', null, InputOption::VALUE_REQUIRED, "Comma-separated schema IDs to {$verb}")
			->addOption('templates', null, InputOption::VALUE_REQUIRED, "Comma-separated template IDs to {$verb}");

		foreach (array_keys(SyncableCollections::FEATURE_FLAGS) as $flag) {
			// VALUE_OPTIONAL with a `false` default gives three states:
			// absent => false, `--pages` => null (all), `--pages=a,b` => 'a,b'.
			$this->addOption($flag, null, InputOption::VALUE_OPTIONAL, sprintf(
				'%s %s — all of them, or =id,id for specific ones',
				ucfirst($verb),
				$flag,
			), false);
		}

		$this
			->addOption('collections', null, InputOption::VALUE_REQUIRED, "Comma-separated collection IDs whose SETTINGS to {$verb} (any collection; counters never travel)")
			->addOption('dry-run', null, InputOption::VALUE_NONE, "Preview what would be {$verb}ed without applying");
	}

	/**
	 * Resolve the category filters from CLI options.
	 *
	 * A bare command (no filter options at all) means "full mirror": every
	 * category resolves to null / all. But the moment ANY filter option is
	 * given, the categories the operator did NOT mention resolve to none.
	 * `tcms push --schemas=blog` means "push the blog schema", not "push the
	 * blog schema, plus every template, plus every object in five collections"
	 * — the old behaviour, where unmentioned categories silently defaulted to
	 * "all", turned a one-schema deploy into an unintended content overwrite
	 * on the target.
	 *
	 * @return array{list<string>|null, list<string>|null, array<string,list<string>|null>|null, list<string>|null}
	 */
	private function resolveSyncFilters(InputInterface $input): array
	{
		$schemas   = $this->parseListOption($input->getOption('schemas'));
		$templates = $this->parseListOption($input->getOption('templates'));
		$settings  = $this->parseListOption($input->getOption('collections'));

		/** @var array<string,list<string>|null> $features */
		$features   = [];
		$anyFeature = false;
		foreach (SyncableCollections::FEATURE_FLAGS as $flag => $collectionId) {
			$value = $input->getOption($flag);
			if ($value === false) {
				continue; // flag not given
			}
			$anyFeature               = true;
			$features[$collectionId] = $this->parseListOption($value);
		}

		$anyFilter = $schemas !== null || $templates !== null || $settings !== null || $anyFeature;

		return [
			$schemas ?? ($anyFilter ? [] : null),
			$templates ?? ($anyFilter ? [] : null),
			$anyFeature ? $features : ($anyFilter ? [] : null),
			$settings ?? ($anyFilter ? [] : null),
		];
	}

	/** @return list<string>|null */
	private function parseListOption(mixed $value): ?array
	{
		if (!is_string($value) || $value === '') {
			return null;
		}

		return array_map(trim(...), explode(',', $value));
	}

	/**
	 * Push-only options. `pull` deliberately does not get these: pulling
	 * production content down to dev has a different risk profile and no
	 * stated use case. Everything the two commands DO share still agrees
	 * exactly, which is what the trait exists to guarantee.
	 */
	private function addPushObjectOptions(): void
	{
		$this
			->addOption(
				'objects',
				null,
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Seed object data: collection, or collection:id,id. Repeatable. Existing objects on the target are left alone',
			)
			->addOption('overwrite', null, InputOption::VALUE_NONE, 'Let --objects overwrite objects that already exist on the target')
			->addOption('force', null, InputOption::VALUE_NONE, 'Required alongside --overwrite: confirms you have seen a --dry-run');
	}

	/**
	 * Parse `--objects` into the exporter's seed filter shape.
	 *
	 * Repeats merge: `--objects=blog:a --objects=blog:b` selects both, and a
	 * bare `--objects=blog` alongside either one widens to the whole
	 * collection, since "all" is a superset of any id list.
	 *
	 * @return array<string,list<string>|null>|null null when --objects was not given
	 */
	private function resolveSeedFilter(InputInterface $input): ?array
	{
		/** @var list<string> $values */
		$values = $input->getOption('objects');
		if ($values === []) {
			return null;
		}

		/** @var array<string,list<string>|null> $filter */
		$filter = [];
		foreach ($values as $value) {
			if (str_contains($value, ':')) {
				[$collectionId, $idPart] = explode(':', $value, 2);
			} else {
				$collectionId = $value;
				$idPart       = null;
			}

			$collectionId = trim($collectionId);
			if ($collectionId === '') {
				throw new \InvalidArgumentException('--objects needs a collection id, e.g. --objects=blog');
			}

			if (!SyncableCollections::seedable($collectionId)) {
				throw new \InvalidArgumentException($this->seedRefusal($collectionId));
			}

			$ids = $idPart === null ? null : $this->parseListOption($idPart);

			// A bare mention means "all", which beats any id list — in
			// either order. Without this guard, a bare mention recorded
			// first (--objects=blog --objects=blog:a) would get silently
			// narrowed back down to ['a'] by the merge below, since
			// `$filter[$collectionId] ?? []` treats the existing `null`
			// (all) as if nothing had been recorded yet.
			if (array_key_exists($collectionId, $filter) && ($filter[$collectionId] === null || $ids === null)) {
				$filter[$collectionId] = null;
				continue;
			}

			$filter[$collectionId] = $ids === null
				? null
				: array_values(array_unique(array_merge($filter[$collectionId] ?? [], $ids)));
		}

		return $filter;
	}

	/** Why a collection cannot be seeded, phrased so the operator knows what to do instead. */
	private function seedRefusal(string $collectionId): string
	{
		$flag = SyncableCollections::flagFor($collectionId);
		if ($flag !== null) {
			return sprintf(
				"'%s' has its own flag — use --%s instead of --objects=%s.",
				$collectionId,
				$flag,
				$collectionId,
			);
		}

		if ($collectionId === 'playground') {
			return "'playground' cannot be seeded: it is a per-install scratchpad, created on demand by whichever environment opens the Twig Playground.";
		}

		return sprintf(
			"'%s' cannot be seeded: binaries never travel, so the objects would arrive pointing at files the target does not have.",
			$collectionId,
		);
	}

	/**
	 * Render a dry-run preview of a sync payload — including the objects,
	 * which the original implementation omitted. Objects are the most
	 * dangerous part of the payload (a push overwrites them on the target),
	 * so a preview that hides them answers the wrong question.
	 *
	 * With a diff (SyncDiffService comparing both sides), the preview shows
	 * what would actually CHANGE — per-item same/differs/new status, and a
	 * which-side-is-newer hint from the `updated` timestamps. Without one
	 * (remote unreachable), it degrades to the plain payload manifest.
	 *
	 * @param array<string,mixed>                                                                              $payload
	 * @param string                                                                                           $verb    'push' or 'pull', for the human headline
	 * @param array{schemas:array<string,mixed>,templates:array<string,mixed>,objects:array<string,mixed>,collections:array<string,mixed>}|null $diff
	 * @param array<string,mixed>|null                                                                         $seeded  A `--objects` seed export (JumpStartData::toArray() shape), shown as
	 *                                                                                                                  a manifest addendum on the diff path — SyncService::diff() has no seed
	 *                                                                                                                  filter, so seeded objects never appear in $diff itself. push-only;
	 *                                                                                                                  pull never has a seed filter to pass here.
	 */
	private function renderSyncDryRun(InputInterface $input, OutputInterface $output, array $payload, string $url, string $verb, ?array $diff = null, ?array $seeded = null): int
	{
		$schemas   = is_array($payload['schemas'] ?? null) ? $payload['schemas'] : [];
		$templates = is_array($payload['templates'] ?? null) ? $payload['templates'] : [];
		$objects   = is_array($payload['objects'] ?? null) ? $payload['objects'] : [];

		$objectsByCollection = $this->groupObjectsByCollection($objects);

		if ($this->isJson($input)) {
			$schemaIds         = array_map(fn (array $s): string => (string)($s['id'] ?? ''), $schemas);
			$templateIds       = array_map(fn (array $t): string => (string)($t['id'] ?? ''), $templates);
			$collectionMetaIds = [];

			// With a diff, the manifest lists are derived from it — the
			// payload isn't separately exported. The sending side's items are
			// everything that isn't exclusive to the receiving side.
			if ($diff !== null) {
				$receivingOnly = $verb === 'push'
					? \TotalCMS\Domain\Sync\Service\SyncDiffService::REMOTE_ONLY
					: \TotalCMS\Domain\Sync\Service\SyncDiffService::LOCAL_ONLY;
				$sourceKeys = fn (array $items): array => array_keys(
					array_filter($items, fn (array $i): bool => $i['status'] !== $receivingOnly)
				);

				$schemaIds           = $sourceKeys($diff['schemas']);
				$templateIds         = $sourceKeys($diff['templates']);
				$collectionMetaIds   = $sourceKeys($diff['collections']);
				$objectsByCollection = [];
				foreach ($this->groupObjectDiffByCollection($diff['objects']) as $collectionId => $items) {
					$ids = $sourceKeys($items);
					if ($ids !== []) {
						$objectsByCollection[$collectionId] = $ids;
					}
				}
			}

			$data = [
				'dry_run'         => true,
				'remote'          => $url,
				'schemas'         => $schemaIds,
				'templates'       => $templateIds,
				'objects'         => $objectsByCollection,
				'collection_meta' => $collectionMetaIds,
			];
			if ($diff !== null) {
				$data['diff'] = $diff;
			}
			if ($seeded !== null) {
				$data['seeded'] = $this->groupObjectsByCollection(is_array($seeded['objects'] ?? null) ? $seeded['objects'] : []);
			}
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return \Symfony\Component\Console\Command\Command::SUCCESS;
		}

		$output->writeln("Dry run — would {$verb} " . ($verb === 'push' ? 'to' : 'from') . " {$url}:");
		$output->writeln('');

		if ($diff !== null) {
			$this->renderDiffCategory($output, 'Schemas', $diff['schemas'], $verb);
			$this->renderDiffCategory($output, 'Collection Settings', $diff['collections'], $verb);

			// A git-managed site excludes templates from sync by design —
			// say so rather than leaving the section silently absent.
			if ($diff['templates'] === [] && $this->totalcms->syncService()->syncableTemplateFilter(null) === []) {
				$output->writeln('Templates:');
				$output->writeln('  managed by git on this site — excluded from sync');
				$output->writeln('');
			} else {
				$this->renderDiffCategory($output, 'Templates', $diff['templates'], $verb);
			}

			// Objects grouped per collection under the collection's display
			// name — the same label the operator sees in the admin sidebar.
			foreach ($this->groupObjectDiffByCollection($diff['objects']) as $collectionId => $items) {
				$this->renderDiffCategory($output, $this->collectionDisplayName((string)$collectionId), $items, $verb);
			}

			// The diff never covers a seed — SyncService::diff() has no seed
			// filter, and diffing against the target isn't even the right
			// question for one (a seed never overwrites). What belongs here
			// instead is a manifest of what will be sent, reusing the same
			// rendering the remote-unreachable fallback below uses.
			$seededByCollection = $seeded !== null
				? $this->groupObjectsByCollection(is_array($seeded['objects'] ?? null) ? $seeded['objects'] : [])
				: [];
			$this->renderObjectManifest(
				$output,
				$seededByCollection,
				'Seeded objects (new on the target; objects it already has are skipped):',
			);

			if ($diff['schemas'] === [] && $diff['templates'] === [] && $diff['objects'] === [] && $diff['collections'] === [] && $seededByCollection === []) {
				$output->writeln('Nothing matches — no schemas, templates, or objects selected.');
			}

			return \Symfony\Component\Console\Command\Command::SUCCESS;
		}

		if ($schemas !== []) {
			$output->writeln('Schemas:');
			foreach ($schemas as $schema) {
				$output->writeln('  - ' . ($schema['id'] ?? 'unknown'));
			}
		}

		if ($templates !== []) {
			$output->writeln('Templates:');
			foreach ($templates as $template) {
				$output->writeln('  - ' . ($template['id'] ?? 'unknown'));
			}
		}

		$this->renderObjectManifest($output, $objectsByCollection, 'Objects (existing objects on the target are overwritten):');

		if ($schemas === [] && $templates === [] && $objectsByCollection === []) {
			$output->writeln('Nothing matches — no schemas, templates, or objects selected.');
		}

		return \Symfony\Component\Console\Command\Command::SUCCESS;
	}

	/**
	 * Group a list of exported object records (JumpStartData::toArray()'s
	 * 'objects' key — each `['collection' => ..., 'id' => ..., 'data' => ...]`)
	 * by collection id, for manifest display.
	 *
	 * @param array<mixed> $objects
	 *
	 * @return array<string,list<string>>
	 */
	private function groupObjectsByCollection(array $objects): array
	{
		$grouped = [];
		foreach ($objects as $object) {
			if (is_array($object)) {
				$grouped[(string)($object['collection'] ?? 'unknown')][] = (string)($object['id'] ?? 'unknown');
			}
		}

		return $grouped;
	}

	/**
	 * Print one "collection (n): id, id, ..." manifest section under a given
	 * heading. Shared by the plain-payload fallback (remote unreachable) and
	 * the seeded-objects addendum on the diff path, so the two renderings
	 * can never drift apart.
	 *
	 * @param array<string,list<string>> $objectsByCollection
	 */
	private function renderObjectManifest(OutputInterface $output, array $objectsByCollection, string $heading): void
	{
		if ($objectsByCollection === []) {
			return;
		}

		$output->writeln($heading);
		foreach ($objectsByCollection as $collectionId => $ids) {
			$output->writeln(sprintf('  %s (%d): %s', $collectionId, count($ids), implode(', ', $ids)));
		}
		$output->writeln('');
	}

	/**
	 * Render one diffed category. Statuses read from the perspective of the
	 * side being applied: on push the local copy lands on the remote, on pull
	 * the remote copy lands locally. Items that exist only on the receiving
	 * side are listed as untouched — sync never deletes.
	 *
	 * @param array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}> $items
	 */
	private function renderDiffCategory(OutputInterface $output, string $title, array $items, string $verb): void
	{
		if ($items === []) {
			return;
		}

		$sourceSide  = $verb === 'push' ? 'local' : 'remote';
		$newStatus   = $verb === 'push' ? \TotalCMS\Domain\Sync\Service\SyncDiffService::LOCAL_ONLY : \TotalCMS\Domain\Sync\Service\SyncDiffService::REMOTE_ONLY;
		$newLabel    = $verb === 'push' ? 'new on remote' : 'new locally';
		$untouchedIn = $verb === 'push' ? 'remote' : 'local';

		$lines        = [];
		$unchangedIds = [];
		$untouchedIds = [];

		foreach ($items as $id => $item) {
			switch ($item['status']) {
				case \TotalCMS\Domain\Sync\Service\SyncDiffService::SAME:
					$unchangedIds[] = (string)$id;
					break;
				case $newStatus:
					$lines[] = sprintf('  <info>+</info> %-28s %s', $id, $newLabel);
					break;
				case \TotalCMS\Domain\Sync\Service\SyncDiffService::DIFFERS:
					$hint    = $this->freshnessHint($item);
					$clobber = $item['newer'] !== null && $item['newer'] !== $sourceSide
						? ' <error>← would overwrite the newer copy</error>'
						: '';
					$lines[] = sprintf('  <comment>~</comment> %-28s differs — %s%s', $id, $hint, $clobber);
					break;
				default: // exists only on the receiving side
					$untouchedIds[] = (string)$id;
			}
		}

		if ($lines === [] && $unchangedIds === [] && $untouchedIds === []) {
			return;
		}

		$output->writeln("{$title}:");
		foreach ($lines as $line) {
			$output->writeln($line);
		}
		if ($unchangedIds !== []) {
			$output->writeln(sprintf('  = %d unchanged: %s', count($unchangedIds), $this->idList($unchangedIds)));
		}
		// Untouched items get a count only: the sync does nothing to them, so
		// they're context rather than a decision. The full id list is in the
		// --json diff for anything scripted.
		if ($untouchedIds !== []) {
			$output->writeln(sprintf(
				'  · %d only on %s — untouched (%s never deletes)',
				count($untouchedIds),
				$untouchedIn,
				$verb,
			));
		}
		$output->writeln('');
	}

	/**
	 * Regroup the flat "collection/id"-keyed object diff back into
	 * per-collection maps keyed by bare object id, for display.
	 *
	 * @param array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}> $objects
	 *
	 * @return array<string,array<string,array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string}>>
	 */
	private function groupObjectDiffByCollection(array $objects): array
	{
		$grouped = [];
		foreach ($objects as $key => $item) {
			[$collectionId, $objectId] = str_contains((string)$key, '/')
				? explode('/', (string)$key, 2)
				: ['unknown', (string)$key];
			$grouped[$collectionId][$objectId] = $item;
		}

		return $grouped;
	}

	/**
	 * The collection's admin display name, falling back to a humanized id
	 * for collections that don't exist locally (e.g. remote-only on a pull).
	 */
	private function collectionDisplayName(string $collectionId): string
	{
		try {
			$collection = $this->totalcms->collectionFetcher()->fetchCollection($collectionId);
			if ($collection !== null && $collection->name !== '') {
				return $collection->name;
			}
		} catch (\Throwable) {
			// Fall through to the humanized id.
		}

		return ucwords(str_replace(['-', '_'], ' ', $collectionId));
	}

	/**
	 * Comma list of ids, capped so a large collection can't flood the
	 * terminal — the count on the same line is always the full truth.
	 *
	 * @param list<string> $ids
	 */
	private function idList(array $ids, int $max = 20): string
	{
		if (count($ids) <= $max) {
			return implode(', ', $ids);
		}

		return implode(', ', array_slice($ids, 0, $max)) . sprintf(', … and %d more', count($ids) - $max);
	}

	/**
	 * @param array{status:string,localUpdated:?string,remoteUpdated:?string,newer:?string} $item
	 */
	private function freshnessHint(array $item): string
	{
		$fmt = function (?string $iso): ?string {
			if ($iso === null) {
				return null;
			}
			$time = strtotime($iso);

			return $time === false ? $iso : date('j M Y H:i', $time);
		};

		$local  = $fmt($item['localUpdated']);
		$remote = $fmt($item['remoteUpdated']);

		if ($item['newer'] === null) {
			return $local === null && $remote === null
				? 'no timestamps to compare'
				: 'timestamps equal';
		}

		if ($local !== null && $remote !== null) {
			return sprintf('%s newer (local %s, remote %s)', $item['newer'], $local, $remote);
		}

		// Only one side is stamped: that side is the hinted-newer one, since
		// an unstamped copy predates timestamp maintenance.
		return sprintf('likely %s newer — the other side has no timestamp', $item['newer']);
	}

	/**
	 * @param array<string,mixed> $data OperationResult data from SyncService
	 */
	private function renderSyncResult(OutputInterface $output, string $message, array $data, ?string $error = null): void
	{
		$output->writeln('');
		$output->writeln($error === null ? "<info>{$message}</info>" : "<error>{$message}</error>");

		// Collections and objects are separate counts. This line used to print
		// the `collections` value under an "Objects" label, so a settings-only
		// sync reported "Objects: 0" and read as a no-op even when it worked.
		$output->writeln(sprintf(
			'  Schemas: %s, Templates: %s, Collections: %s, Objects: %s',
			$data['schemas'] ?? 0,
			$data['templates'] ?? 0,
			$data['collections'] ?? 0,
			$data['objects'] ?? 0,
		));

		if ($error !== null) {
			$output->writeln('');
			foreach (explode('; ', $error) as $line) {
				$output->writeln("  <error>{$line}</error>");
			}
		}
	}
}
