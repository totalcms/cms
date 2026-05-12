<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SchemaImportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('schema:import')
			->setDescription('Import a schema from a JSON file')
			->addArgument('file', InputArgument::REQUIRED, 'Path to schema JSON file');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$filePath = (string)$input->getArgument('file');

		if (!file_exists($filePath)) {
			return $this->outputError($input, $output, "File not found: {$filePath}");
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			return $this->outputError($input, $output, "Cannot read file: {$filePath}");
		}

		$schemaData = json_decode($content, true);
		if (!is_array($schemaData)) {
			return $this->outputError($input, $output, 'Invalid JSON in schema file.');
		}

		try {
			$schema = $this->totalcms->schemaSaver()->saveSchema($schemaData);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Schema import failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'success' => true,
				'id'      => $schema->id,
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Schema '{$schema->id}' imported successfully.</info>");

		return Command::SUCCESS;
	}
}
