<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

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
		$id        = (string)$input->getArgument('id');
		$discovery = $this->totalcms->container()->get(ExtensionDiscovery::class);
		$manager   = $this->totalcms->container()->get(ExtensionManager::class);
		$stateRepo = $this->totalcms->container()->get(ExtensionStateRepository::class);

		$extPath = $discovery->getExtensionPath($id);
		if ($extPath === null) {
			return $this->outputError($input, $output, "Extension '{$id}' not found");
		}

		if (!$input->getOption('force') && !$this->isJson($input)) {
			/** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
			$helper   = $this->getHelper('question');
			$question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
				"Remove extension '{$id}'? This deletes the extension files but NOT its data. [y/N] ",
				false,
			);

			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('Aborted.');

				return Command::SUCCESS;
			}
		}

		// Disable first
		$manager->disable($id);

		// Remove the extension directory
		$this->removeDirectory($extPath);

		// Clean up state
		$stateRepo->removeState($id);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['status' => 'removed', 'id' => $id]));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Extension '{$id}' removed.</info>");
		$output->writeln('<comment>Extension data in tcms-data was preserved.</comment>');

		return Command::SUCCESS;
	}

	private function removeDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			if ($item->isDir()) {
				rmdir($item->getRealPath());
			} else {
				unlink($item->getRealPath());
			}
		}

		rmdir($path);
	}
}
