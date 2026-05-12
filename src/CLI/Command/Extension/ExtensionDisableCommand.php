<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

class ExtensionDisableCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('extension:disable')
			->setDescription('Disable an extension')
			->addArgument('id', InputArgument::REQUIRED, 'Extension ID (e.g. vendor/extension-name)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id      = (string)$input->getArgument('id');
		$manager = $this->totalcms->container()->get(ExtensionManager::class);

		$manager->disable($id);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['status' => 'disabled', 'id' => $id]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Extension '{$id}' disabled.</info> Restart your app for changes to take effect.");

		return Command::SUCCESS;
	}
}
