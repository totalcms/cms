<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SchemaExportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('schema:export')
			->setDescription('Export a schema to a JSON file')
			->addArgument('id', InputArgument::REQUIRED, 'Schema ID')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (omit for stdout)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id      = (string)$input->getArgument('id');
		$fetcher = $this->totalcms->schemaFetcher();

		if (!$fetcher->schemaExists($id)) {
			return $this->outputError($input, $output, "Schema '{$id}' not found.");
		}

		$schema     = $fetcher->fetchSchema($id);
		$json       = (string)json_encode($schema->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$outputFile = $input->getOption('output');

		if (is_string($outputFile)) {
			file_put_contents($outputFile, $json);
			$output->writeln("<info>Schema '{$id}' exported to {$outputFile}</info>");

			return Command::SUCCESS;
		}

		$output->writeln($json, OutputInterface::OUTPUT_RAW);

		return Command::SUCCESS;
	}
}
