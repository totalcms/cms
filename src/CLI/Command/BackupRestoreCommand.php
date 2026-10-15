<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Put a snapshot back as the live record. Goes through ObjectRestorer →
 * ObjectUpdater, so the index rebuilds and the state being replaced is
 * itself snapshotted first — a restore is never a one-way door.
 */
class BackupRestoreCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('backup:restore')
			->setDescription('Restore an object from one of its snapshots')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('id', InputArgument::REQUIRED, 'Object ID')
			->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot filename from backup:list (omit with --latest)')
			->addOption('latest', null, InputOption::VALUE_NONE, 'Restore the newest snapshot')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('id');
		$snapshot     = (string)($input->getArgument('snapshot') ?? '');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		$store = $this->totalcms->backupStore();

		if ($snapshot === '') {
			if (!$input->getOption('latest')) {
				return $this->outputError($input, $output, 'Give a snapshot filename (see backup:list) or pass --latest.');
			}

			$snapshot = (string)$store->latestObjectSnapshot($collectionId, $objectId);
			if ($snapshot === '') {
				return $this->outputError($input, $output, "No snapshots exist for {$collectionId}/{$objectId}.");
			}
		} elseif ($store->readObjectSnapshot($collectionId, $objectId, $snapshot) === null) {
			return $this->outputError($input, $output, "Snapshot '{$snapshot}' not found for {$collectionId}/{$objectId}. Run backup:list to see what exists.");
		}

		$live = $this->totalcms->objectFetcher()->existsObject($collectionId, $objectId);

		$refused = $this->confirmOrRefuse($input, $output, sprintf(
			"%s '%s' in '%s' from %s? The current state is snapshotted first. [y/N] ",
			$live ? 'Overwrite' : 'Recreate',
			$objectId,
			$collectionId,
			$snapshot,
		));
		if ($refused !== null) {
			return $refused;
		}

		try {
			$this->totalcms->objectRestorer()->restore($collectionId, $objectId, $snapshot);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Restore failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'status'     => $live ? 'restored' : 'recreated',
				'collection' => $collectionId,
				'id'         => $objectId,
				'snapshot'   => $snapshot,
			]));

			return Command::SUCCESS;
		}

		$output->writeln(sprintf(
			"<info>Object '%s' %s in '%s' from %s.</info>",
			$objectId,
			$live ? 'restored' : 'recreated',
			$collectionId,
			$snapshot,
		));

		return Command::SUCCESS;
	}
}
