<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

/**
 * Show every snapshot kept for one object, newest first. The filename in the
 * first column is what `backup:restore` takes.
 */
class BackupListCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('backup:list')
			->setDescription('List the snapshots kept for an object')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('id', InputArgument::REQUIRED, 'Object ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('id');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		$snapshots = $this->totalcms->backupStore()->listObjectSnapshots($collectionId, $objectId);

		return $this->outputData($input, $output, [
			'collection' => $collectionId,
			'id'         => $objectId,
			'live'       => $this->totalcms->objectFetcher()->existsObject($collectionId, $objectId),
			'snapshots'  => $snapshots,
		]);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		/** @var list<array{file:string,at:string,format:string,bytes:int}> $snapshots */
		$snapshots = is_array($data['snapshots'] ?? null) ? $data['snapshots'] : [];
		$live      = (bool)($data['live'] ?? false);

		$output->writeln('');

		if ($snapshots === []) {
			$output->writeln(sprintf(
				'<comment>No snapshots for %s/%s.</comment>%s',
				(string)$data['collection'],
				(string)$data['id'],
				$live ? '' : ' The object does not exist either.',
			));
			$output->writeln('');

			return;
		}

		$output->writeln(sprintf(
			'<info>%d snapshot%s for %s/%s</info>%s',
			count($snapshots),
			count($snapshots) === 1 ? '' : 's',
			(string)$data['collection'],
			(string)$data['id'],
			$live ? '' : ' <comment>(object is deleted — restore brings it back)</comment>',
		));
		$output->writeln('');

		$rows = [];
		foreach ($snapshots as $i => $snapshot) {
			$rows[] = [
				$snapshot['file'],
				$snapshot['at'] !== '' ? str_replace('T', ' ', substr($snapshot['at'], 0, 19)) : '?',
				$snapshot['format'],
				$this->humanBytes($snapshot['bytes']),
				$i === 0 ? 'latest' : '',
			];
		}

		TableHelper::renderList($output, ['Snapshot', 'Taken', 'Format', 'Size', ''], $rows);
		$output->writeln('');
		$output->writeln(sprintf(
			'Restore one with: <comment>tcms backup:restore %s %s <snapshot></comment> (or <comment>--latest</comment>)',
			(string)$data['collection'],
			(string)$data['id'],
		));
		$output->writeln('');
	}

	private function humanBytes(int $bytes): string
	{
		if ($bytes >= 1048576) {
			return sprintf('%.1f MB', $bytes / 1048576);
		}
		if ($bytes >= 1024) {
			return sprintf('%.1f KB', $bytes / 1024);
		}

		return "{$bytes} B";
	}
}
