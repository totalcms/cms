<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ObjectExportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('object:export')
			->setDescription('Export a single object as JSON or ZIP (with assets)')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('id', InputArgument::REQUIRED, 'Object ID')
			->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Export format: json or zip', 'json')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (omit for stdout)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('id');
		$format       = (string)$input->getOption('format');
		$outputFile   = $input->getOption('output');

		if (!$this->totalcms->objectFetcher()->existsObject($collectionId, $objectId)) {
			return $this->outputError($input, $output, "Object '{$objectId}' not found in collection '{$collectionId}'.");
		}

		if ($format === 'zip') {
			return $this->exportZip($input, $output, $collectionId, $objectId, $outputFile);
		}

		return $this->exportJson($output, $collectionId, $objectId, $outputFile);
	}

	private function exportJson(OutputInterface $output, string $collectionId, string $objectId, mixed $outputFile): int
	{
		$object = $this->totalcms->objectFetcher()->fetchObject($collectionId, $objectId);
		$json   = (string)json_encode($object->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if (is_string($outputFile)) {
			file_put_contents($outputFile, $json);
			$output->writeln("<info>Exported to {$outputFile}</info>");

			return Command::SUCCESS;
		}

		$output->writeln($json, OutputInterface::OUTPUT_RAW);

		return Command::SUCCESS;
	}

	private function exportZip(InputInterface $input, OutputInterface $output, string $collectionId, string $objectId, mixed $outputFile): int
	{
		try {
			$zipper  = $this->totalcms->objectZipper();
			$zipPath = $zipper->createObjectZip($collectionId, $objectId);
		} catch (\RuntimeException $e) {
			return $this->outputError($input, $output, "Zip export failed: {$e->getMessage()}");
		}

		$destination = is_string($outputFile) ? $outputFile : $zipper->getZipFilename($collectionId, $objectId);

		rename($zipPath, $destination);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'success' => true,
				'file'    => $destination,
				'size'    => filesize($destination),
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		$size          = filesize($destination);
		$sizeFormatted = $size !== false ? round($size / 1024 / 1024, 2) . ' MB' : 'unknown';
		$output->writeln("<info>Exported to {$destination} ({$sizeFormatted})</info>");

		return Command::SUCCESS;
	}
}
