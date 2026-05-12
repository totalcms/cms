<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\FileHelper;

class DeckImportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('deck:import')
			->setDescription('Import items into a deck property from JSON or CSV')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('object', InputArgument::REQUIRED, 'Object ID')
			->addArgument('property', InputArgument::REQUIRED, 'Deck property name')
			->addArgument('file', InputArgument::REQUIRED, 'Path to JSON or CSV file')
			->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Import format: json or csv (auto-detected from extension)')
			->addOption('update', null, InputOption::VALUE_NONE, 'Update existing deck items instead of skipping');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('object');
		$property     = (string)$input->getArgument('property');
		$filePath     = (string)$input->getArgument('file');
		$update       = (bool)$input->getOption('update');

		if (!file_exists($filePath)) {
			return $this->outputError($input, $output, "File not found: {$filePath}");
		}

		if (!$this->totalcms->objectFetcher()->existsObject($collectionId, $objectId)) {
			return $this->outputError($input, $output, "Object '{$objectId}' not found in collection '{$collectionId}'.");
		}

		// Auto-detect format
		$format = $input->getOption('format');
		if (!is_string($format) || $format === '') {
			$ext    = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
			$format = $ext === 'csv' ? 'csv' : 'json';
		}

		$uploadedFile = FileHelper::createUploadedFile($filePath);

		try {
			if ($format === 'csv') {
				$count = $this->totalcms->deckCsvImporter()->import($collectionId, $objectId, $property, $uploadedFile, $update);
			} else {
				$count = $this->totalcms->deckJsonImporter()->import($collectionId, $objectId, $property, $uploadedFile, $update);
			}
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Import failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'success'    => true,
				'imported'   => $count,
				'collection' => $collectionId,
				'object'     => $objectId,
				'property'   => $property,
				'format'     => $format,
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		$output->writeln("<info>{$count} deck item(s) imported into '{$collectionId}/{$objectId}/{$property}'.</info>");

		return Command::SUCCESS;
	}
}
