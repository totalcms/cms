<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Extension\Service\Install\ExtensionRemover;
use TotalCMS\Domain\Extension\Service\Install\InstallException;

class ExtensionRemoveCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('extension:remove')
			->setDescription('Remove an extension (does not delete extension data)')
			->addArgument('id', InputArgument::REQUIRED, 'Extension ID (e.g. vendor/extension-name)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id      = (string)$input->getArgument('id');
		$remover = $this->totalcms->container()->get(ExtensionRemover::class);

		// Surface not-found and ownership refusals BEFORE asking for
		// confirmation, so nobody confirms a removal that was never possible.
		try {
			$remover->assertRemovable($id);
		} catch (InstallException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		$refused = $this->confirmOrRefuse($input, $output, "Remove extension '{$id}'? This deletes the extension files but NOT its data. [y/N] ");
		if ($refused !== null) {
			return $refused;
		}

		try {
			$remover->remove($id);
		} catch (InstallException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['status' => 'removed', 'id' => $id]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Extension '{$id}' removed.</info>");
		$output->writeln('<comment>Extension data in tcms-data was preserved.</comment>');

		return Command::SUCCESS;
	}
}
