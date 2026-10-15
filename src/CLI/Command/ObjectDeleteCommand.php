<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete a single object. Goes through ObjectRemover, which dispatches
 * OBJECT_DELETED and rebuilds the collection index + meta count — so the
 * delete stays consistent (unlike removing the flat file by hand, which
 * leaves .index.json and the totalObjects count stale).
 */
class ObjectDeleteCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('object:delete')
			->setDescription('Delete a single object (updates the collection index)')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('id', InputArgument::REQUIRED, 'Object ID')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('id');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		if (!$this->totalcms->objectFetcher()->existsObject($collectionId, $objectId)) {
			return $this->outputError($input, $output, "Object '{$objectId}' not found in collection '{$collectionId}'.");
		}

		$refused = $this->confirmOrRefuse($input, $output, "Delete object '{$objectId}' from '{$collectionId}'? This cannot be undone. [y/N] ");
		if ($refused !== null) {
			return $refused;
		}

		$deleted = $this->totalcms->objectRemover()->deleteObject($collectionId, $objectId);

		if (!$deleted) {
			return $this->outputError($input, $output, "Failed to delete object '{$objectId}' from '{$collectionId}'.");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'status'     => 'deleted',
				'collection' => $collectionId,
				'id'         => $objectId,
			]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Object '{$objectId}' deleted from '{$collectionId}'.</info>");

		return Command::SUCCESS;
	}
}
