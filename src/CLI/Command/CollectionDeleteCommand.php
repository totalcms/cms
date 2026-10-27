<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Delete a collection's settings. Goes through CollectionRemover, as the
 * admin, REST and MCP deletes do: login collections are refused, the
 * settings are snapshotted to the backup history, and COLLECTION_DELETED
 * fires.
 *
 * Objects and files stay on disk, so creating a collection with the same id
 * brings them back. Anything that still points at the collection (a Data
 * View, another collection's owner or access collection, the public
 * registration list) stops the delete unless --force is passed, because
 * those break later and quietly.
 */
class CollectionDeleteCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:delete')
			->setDescription('Delete a collection (its objects stay on disk)')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation, and delete even when something still refers to the collection');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id         = (string)$input->getArgument('id');
		$collection = $this->totalcms->collectionFetcher()->fetchCollection($id);

		if (!$collection instanceof CollectionData) {
			return $this->outputError($input, $output, "Collection '{$id}' not found.");
		}

		if (in_array($id, $this->totalcms->collectionRemover()->loginCollections(), true)) {
			return $this->outputError($input, $output, "Collection '{$id}' is a login collection: the site signs people in with it, so it cannot be deleted.");
		}

		$references = $this->totalcms->collectionReferences()->find($id);
		if ($references !== [] && !$input->getOption('force')) {
			return $this->outputError($input, $output, sprintf(
				"Collection '%s' is still referred to by:\n  - %s\nUpdate those first, or pass --force to delete it anyway.",
				$id,
				implode("\n  - ", $references),
			));
		}

		if ($references !== [] && !$this->isJson($input)) {
			$output->writeln("<comment>Deleting anyway. These still refer to '{$id}':</comment>");
			foreach ($references as $reference) {
				$output->writeln("  - {$reference}");
			}
		}

		$objects = max(0, $collection->totalObjects);
		$refused = $this->confirmOrRefuse($input, $output, "Delete collection '{$id}'? Its {$objects} objects stay on disk. [y/N] ");
		if ($refused !== null) {
			return $refused;
		}

		try {
			$deleted = $this->totalcms->collectionRemover()->deleteCollection($id);
		} catch (\DomainException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if (!$deleted) {
			return $this->outputError($input, $output, "Failed to delete collection '{$id}'.");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'status'      => 'deleted',
				'id'          => $id,
				'objectsKept' => $objects,
				'references'  => $references,
			]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Collection '{$id}' deleted.</info>");
		$output->writeln("{$objects} objects kept on disk. Create a collection with the id '{$id}' to bring them back.");
		$output->writeln('Its settings were saved to ' . BackupStore::BACKUP_ROOT . "/collections/{$id}/.");

		return Command::SUCCESS;
	}
}
