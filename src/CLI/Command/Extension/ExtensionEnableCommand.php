<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

class ExtensionEnableCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('extension:enable')
			->setDescription('Enable an extension')
			->addArgument('id', InputArgument::REQUIRED, 'Extension ID (e.g. vendor/extension-name)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id        = (string)$input->getArgument('id');
		$discovery = $this->totalcms->container()->get(ExtensionDiscovery::class);
		$manager   = $this->totalcms->container()->get(ExtensionManager::class);

		$manifests = $discovery->discover();
		if (!isset($manifests[$id])) {
			return $this->outputError($input, $output, "Extension '{$id}' not found");
		}

		$manager->enable($id);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['status' => 'enabled', 'id' => $id]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Extension '{$id}' enabled.</info> Restart your app for changes to take effect.");

		return Command::SUCCESS;
	}
}
