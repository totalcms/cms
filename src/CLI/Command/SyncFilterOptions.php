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
	private function addSyncFilterOptions(string $verb): void
	{
		$this
			->addOption('schemas', null, InputOption::VALUE_REQUIRED, "Comma-separated schema IDs to {$verb}")
			->addOption('templates', null, InputOption::VALUE_REQUIRED, "Comma-separated template IDs to {$verb}")
			->addOption('collections', null, InputOption::VALUE_REQUIRED, sprintf(
				'Comma-separated collection IDs whose objects to %s (allowed: %s)',
				$verb,
				implode(', ', SyncableCollections::IDS),
			))
			->addOption('dry-run', null, InputOption::VALUE_NONE, "Preview what would be {$verb}ed without applying");
	}

	/**
	 * Resolve the three category filters from CLI options.
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
	 * @return array{list<string>|null, list<string>|null, array<string,null>|null}
	 */
	private function resolveSyncFilters(InputInterface $input): array
	{
		$schemas     = $this->parseListOption($input->getOption('schemas'));
		$templates   = $this->parseListOption($input->getOption('templates'));
		$collections = $this->parseListOption($input->getOption('collections'));

		if ($collections !== null) {
			$unknown = array_diff($collections, SyncableCollections::IDS);
			if ($unknown !== []) {
				throw new \InvalidArgumentException(sprintf(
					'Unknown sync collection(s): %s. Objects can only sync from: %s.',
					implode(', ', $unknown),
					implode(', ', SyncableCollections::IDS),
				));
			}
		}

		$anyFilter = $schemas !== null || $templates !== null || $collections !== null;

		return [
			$schemas ?? ($anyFilter ? [] : null),
			$templates ?? ($anyFilter ? [] : null),
			$collections !== null
				? array_fill_keys($collections, null)
				: ($anyFilter ? [] : null),
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
	 * @param array{schemas:array<string,mixed>,templates:array<string,mixed>,objects:array<string,mixed>}|null $diff
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
			$data = [
				'dry_run'   => true,
				'remote'    => $url,
				'schemas'   => array_map(fn (array $s): string => (string)($s['id'] ?? ''), $schemas),
				'templates' => array_map(fn (array $t): string => (string)($t['id'] ?? ''), $templates),
				'objects'   => $objectsByCollection,
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
			$this->renderDiffCategory($output, 'Templates', $diff['templates'], $verb);
			$this->renderDiffCategory($output, 'Objects', $diff['objects'], $verb);

			if ($diff['schemas'] === [] && $diff['templates'] === [] && $diff['objects'] === []) {
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

		$lines     = [];
		$unchanged = 0;
		$untouched = 0;

		foreach ($items as $id => $item) {
			switch ($item['status']) {
				case \TotalCMS\Domain\Sync\Service\SyncDiffService::SAME:
					$unchanged++;
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
					$untouched++;
			}
		}

		if ($lines === [] && $unchanged === 0 && $untouched === 0) {
			return;
		}

		$output->writeln("{$title}:");
		foreach ($lines as $line) {
			$output->writeln($line);
		}
		if ($unchanged > 0) {
			$output->writeln("  = {$unchanged} unchanged");
		}
		if ($untouched > 0) {
			$output->writeln("  · {$untouched} only on {$untouchedIn} — untouched ({$verb} never deletes)");
		}
		$output->writeln('');
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
	private function renderSyncResult(OutputInterface $output, string $message, array $data): void
	{
		$output->writeln('');
		$output->writeln("<info>{$message}</info>");
		$output->writeln(sprintf(
			'  Schemas: %s, Templates: %s, Objects: %s',
			$data['schemas'] ?? 0,
			$data['templates'] ?? 0,
			$data['collections'] ?? 0,
		));
	}
}
