<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JumpStartImportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('jumpstart:import')
			->setDescription('Import a JumpStart file')
			->addArgument('file', InputArgument::REQUIRED, 'Path to JumpStart JSON file');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$filePath = (string)$input->getArgument('file');

		if (!file_exists($filePath)) {
			return $this->outputError($input, $output, "File not found: {$filePath}");
		}

		if (!$this->isJson($input)) {
			$output->writeln("Importing JumpStart from <info>{$filePath}</info>...");
		}

		try {
			$result = $this->totalcms->jumpStartImporter()->importFromFile($filePath);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Import failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$summary = $result->data['summary'];
		$errors  = $result->data['errors'];

		$output->writeln('');
		$output->writeln('<info>JumpStart import complete.</info>');
		foreach ($summary as $key => $value) {
			$output->writeln("  {$key}: {$value}");
		}

		if ($errors !== []) {
			$output->writeln('');
			$output->writeln('<comment>Errors:</comment>');
			foreach ($errors as $error) {
				$output->writeln("  - {$error}");
			}
		}

		return Command::SUCCESS;
	}
}
