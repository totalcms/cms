<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\FileHelper;

class CollectionImportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:import')
			->setDescription('Import objects into a collection from JSON or CSV')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('file', InputArgument::REQUIRED, 'Path to JSON or CSV file')
			->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Import format: json or csv (auto-detected from extension)')
			->addOption('update', null, InputOption::VALUE_NONE, 'Update existing objects instead of skipping');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('id');
		$filePath     = (string)$input->getArgument('file');
		$update       = (bool)$input->getOption('update');

		if (!file_exists($filePath)) {
			return $this->outputError($input, $output, "File not found: {$filePath}");
		}

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		// Auto-detect format from extension if not specified
		$format = $input->getOption('format');
		if (!is_string($format) || $format === '') {
			$ext    = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
			$format = $ext === 'csv' ? 'csv' : 'json';
		}

		$uploadedFile = FileHelper::createUploadedFile($filePath);

		try {
			if ($format === 'csv') {
				$count = $this->totalcms->csvImporter()->import($collectionId, $uploadedFile, $update);
			} else {
				$count = $this->totalcms->jsonImporter()->import($collectionId, $uploadedFile, $update);
			}
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Import failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'success'  => true,
				'imported' => $count,
				'format'   => $format,
				'update'   => $update,
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		$mode = $update ? 'updated' : 'imported';
		$output->writeln("<info>{$count} object(s) {$mode} into '{$collectionId}'.</info>");

		return Command::SUCCESS;
	}
}
