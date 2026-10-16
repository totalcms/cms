<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Extension\Service\Install\ExtensionPackager;
use TotalCMS\Domain\Extension\Service\Install\InstallException;

/**
 * Build the distributable zip for an extension — the shape
 * `extension:install` and the admin upload accept.
 */
class ExtensionPackageCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('extension:package')
			->setDescription('Package an extension directory as {name}-{version}.zip for distribution')
			->addArgument('dir', InputArgument::OPTIONAL, 'Extension directory (contains extension.json); defaults to the current directory')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Directory to write the zip into (default: next to the extension directory)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing zip');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dir    = (string)($input->getArgument('dir') ?? getcwd());
		$outDir = $input->getOption('output');

		try {
			$result = $this->totalcms->container()->get(ExtensionPackager::class)->package(
				$dir,
				is_string($outDir) && $outDir !== '' ? $outDir : null,
				(bool)$input->getOption('force'),
			);
		} catch (InstallException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Packaged {$result->fileCount} files into {$result->zipPath}</info>");
		foreach ($result->warnings as $warning) {
			$output->writeln("<comment>Warning: {$warning}</comment>");
		}

		return Command::SUCCESS;
	}
}
