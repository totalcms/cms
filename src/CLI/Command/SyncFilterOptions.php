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
	 */
	private function renderSyncDryRun(InputInterface $input, OutputInterface $output, array $payload, string $url, string $verb, ?array $diff = null): int
	{
		$schemas   = is_array($payload['schemas'] ?? null) ? $payload['schemas'] : [];
		$templates = is_array($payload['templates'] ?? null) ? $payload['templates'] : [];
		$objects   = is_array($payload['objects'] ?? null) ? $payload['objects'] : [];

		// Group object ids by collection for display.
		/** @var array<string,list<string>> $objectsByCollection */
		$objectsByCollection = [];
		foreach ($objects as $object) {
			if (is_array($object)) {
				$objectsByCollection[(string)($object['collection'] ?? 'unknown')][] = (string)($object['id'] ?? 'unknown');
			}
		}

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

			if ($diff['schemas'] === [] && $diff['templates'] === [] && $diff['objects'] === [] && $diff['collections'] === []) {
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

		if ($objectsByCollection !== []) {
			$output->writeln('Objects (existing objects on the target are overwritten):');
			foreach ($objectsByCollection as $collectionId => $ids) {
				$output->writeln(sprintf('  %s (%d): %s', $collectionId, count($ids), implode(', ', $ids)));
			}
		}

		if ($schemas === [] && $templates === [] && $objectsByCollection === []) {
			$output->writeln('Nothing matches — no schemas, templates, or objects selected.');
		}

		return \Symfony\Component\Console\Command\Command::SUCCESS;
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
