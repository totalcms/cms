<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Backup\Service\BackupStore;

/**
 * Delete a custom schema. Goes through SchemaRemover, as the admin, REST
 * and MCP deletes do: reserved schemas, schemas another schema inherits and
 * schemas a collection still uses are refused, and the schema file is
 * snapshotted to the backup history first.
 */
class SchemaDeleteCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('schema:delete')
			->setDescription('Delete a custom schema')
			->addArgument('id', InputArgument::REQUIRED, 'Schema ID')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id = (string)$input->getArgument('id');

		if (!$this->totalcms->schemaFetcher()->schemaExists($id)) {
			return $this->outputError($input, $output, "Schema '{$id}' not found.");
		}

		$refused = $this->confirmOrRefuse($input, $output, "Delete schema '{$id}'? [y/N] ");
		if ($refused !== null) {
			return $refused;
		}

		try {
			$deleted = $this->totalcms->schemaRemover()->deleteSchema($id);
		} catch (\DomainException | \UnexpectedValueException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if (!$deleted) {
			return $this->outputError($input, $output, "Failed to delete schema '{$id}'. Only custom schemas can be deleted: an extension's schemas go when the extension is removed.");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'status' => 'deleted',
				'id'     => $id,
			]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Schema '{$id}' deleted.</info>");
		$output->writeln('A copy was saved to ' . BackupStore::BACKUP_ROOT . "/schemas/{$id}/.");

		return Command::SUCCESS;
	}
}
