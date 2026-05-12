<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use League\Csv\Writer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CollectionExportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:export')
			->setDescription('Export a collection to JSON, CSV, or ZIP')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID')
			->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Export format: json, csv, or zip', 'json')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (omit for stdout)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('id');
		$format       = (string)$input->getOption('format');
		$outputFile   = $input->getOption('output');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		if ($format === 'zip') {
			return $this->exportZip($input, $output, $collectionId, $outputFile);
		}

		$index = $this->totalcms->indexReader()->fetchIndex($collectionId);
		$total = $index->objects->count();

		// Stream JSON directly to file for large collections
		if ($format === 'json' && is_string($outputFile)) {
			return $this->streamJsonToFile($input, $output, $collectionId, $index, $outputFile, $total);
		}

		// For stdout/CSV, warn if collection is very large
		if (!is_string($outputFile) && $total > 1000) {
			return $this->outputError(
				$input,
				$output,
				"Collection '{$collectionId}' has {$total} objects. Use --output=file.json to stream to a file."
			);
		}

		// For stdout or CSV, load all objects into memory
		$objects = $this->fetchAllObjects($output, $collectionId, $index, $total);

		if ($objects === null) {
			return $this->outputError($input, $output, "Failed to fetch any of the {$total} objects in '{$collectionId}'. Run with -v for details.");
		}

		if ($format === 'csv') {
			return $this->exportCsv($output, $objects, $outputFile);
		}

		return $this->exportJson($output, $objects, $outputFile);
	}

	/**
	 * Stream JSON objects directly to a file one at a time to avoid memory exhaustion.
	 */
	private function streamJsonToFile(
		InputInterface $input,
		OutputInterface $output,
		string $collectionId,
		\TotalCMS\Domain\Index\Data\IndexData $index,
		string $outputFile,
		int $total,
	): int {
		$handle = fopen($outputFile, 'w');
		if ($handle === false) {
			return $this->outputError($input, $output, "Cannot write to {$outputFile}");
		}

		fwrite($handle, "[\n");

		$written = 0;
		$errors  = 0;

		foreach ($index->objects as $entry) {
			$id = $entry['id'] ?? null;
			if (!is_string($id)) {
				continue;
			}

			try {
				$object = $this->totalcms->objectFetcher()->fetchObject($collectionId, $id);
				$json   = (string)json_encode($object->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

				if ($written > 0) {
					fwrite($handle, ",\n");
				}

				// Indent each line of the object JSON by 4 spaces
				$indented = preg_replace('/^/m', '    ', $json);
				fwrite($handle, (string)$indented);
				$written++;
				unset($object, $json, $indented);
			} catch (\Throwable $e) {
				$errors++;
				if ($output->isVerbose()) {
					$stderr = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
						? $output->getErrorOutput()
						: $output;
					$stderr->writeln("<comment>Skipped {$id}: {$e->getMessage()}</comment>");
				}
			}
		}

		fwrite($handle, "\n]");
		fclose($handle);

		if ($written === 0 && $total > 0) {
			@unlink($outputFile);

			return $this->outputError($input, $output, "Failed to fetch any of the {$total} objects in '{$collectionId}'. Run with -v for details.");
		}

		if (!$this->isJson($input)) {
			if ($errors > 0) {
				$output->writeln("<comment>Warning: {$errors} of {$total} objects could not be fetched.</comment>");
			}
			$output->writeln("<info>Exported {$written} objects to {$outputFile}</info>");
		}

		return Command::SUCCESS;
	}

	/**
	 * Fetch all objects into memory (for stdout/CSV).
	 *
	 * @return list<array<string,mixed>>|null
	 */
	private function fetchAllObjects(
		OutputInterface $output,
		string $collectionId,
		\TotalCMS\Domain\Index\Data\IndexData $index,
		int $total,
	): ?array {
		$objects = [];
		$errors  = 0;

		foreach ($index->objects as $entry) {
			$id = $entry['id'] ?? null;
			if (!is_string($id)) {
				continue;
			}
			try {
				$object    = $this->totalcms->objectFetcher()->fetchObject($collectionId, $id);
				$objects[] = $object->toArray();
			} catch (\Throwable $e) {
				$errors++;
				if ($output->isVerbose()) {
					$stderr = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
						? $output->getErrorOutput()
						: $output;
					$stderr->writeln("<comment>Skipped {$id}: {$e->getMessage()}</comment>");
				}
			}
		}

		if ($objects === [] && $total > 0) {
			return null;
		}

		if ($errors > 0) {
			$output->writeln("<comment>Warning: {$errors} of {$total} objects could not be fetched.</comment>");
		}

		return $objects;
	}

	/**
	 * @param list<array<string,mixed>> $objects
	 */
	private function exportJson(OutputInterface $output, array $objects, mixed $outputFile): int
	{
		$json = (string)json_encode($objects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if (is_string($outputFile)) {
			file_put_contents($outputFile, $json);
			$output->writeln('<info>Exported ' . count($objects) . " objects to {$outputFile}</info>");

			return Command::SUCCESS;
		}

		$output->writeln($json, OutputInterface::OUTPUT_RAW);

		return Command::SUCCESS;
	}

	/**
	 * @param list<array<string,mixed>> $objects
	 */
	private function exportCsv(OutputInterface $output, array $objects, mixed $outputFile): int
	{
		if ($objects === []) {
			$output->writeln('No objects to export.');

			return Command::SUCCESS;
		}

		// Build CSV from object data
		$firstObject = $objects[0];
		$headers     = array_keys($firstObject);

		$csv = Writer::createFromString();
		$csv->insertOne($headers);

		foreach ($objects as $obj) {
			$row = [];
			foreach ($headers as $key) {
				$value = $obj[$key] ?? '';
				$row[] = is_array($value)
					? (string)json_encode($value, JSON_UNESCAPED_SLASHES)
					: (string)$value;
			}
			$csv->insertOne($row);
		}

		$csvContent = $csv->toString();

		if (is_string($outputFile)) {
			file_put_contents($outputFile, $csvContent);
			$output->writeln('<info>Exported ' . count($objects) . " objects to {$outputFile}</info>");

			return Command::SUCCESS;
		}

		$output->writeln($csvContent, OutputInterface::OUTPUT_RAW);

		return Command::SUCCESS;
	}

	private function exportZip(InputInterface $input, OutputInterface $output, string $collectionId, mixed $outputFile): int
	{
		try {
			$zipper  = $this->totalcms->collectionZipper();
			$zipPath = $zipper->createCollectionZip($collectionId);
		} catch (\RuntimeException $e) {
			return $this->outputError($input, $output, "Zip export failed: {$e->getMessage()}");
		}

		$destination = is_string($outputFile) ? $outputFile : $zipper->getZipFilename($collectionId);
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
