<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class JumpStartExportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('jumpstart:export')
			->setDescription('Export site data as a JumpStart file')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name for the export', '')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description for the export', '')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$name        = (string)$input->getOption('name');
		$description = (string)$input->getOption('description');
		$outputFile  = $input->getOption('output');

		$exporter = $this->totalcms->jumpStartExporter();
		$exporter->setMetadata($name, $description);

		if (!$this->isJson($input)) {
			$output->writeln('Exporting JumpStart data...');
		}

		$jumpstart = $exporter->exportCurrentData();

		$json = $jumpstart->toJson();

		// Default filename if not specified
		if (!is_string($outputFile) || $outputFile === '') {
			$date       = date('Ymd-His');
			$slug       = $name !== '' ? preg_replace('/[^a-zA-Z0-9-_]/', '-', strtolower($name)) : 'export';
			$outputFile = "jumpstart-{$slug}-{$date}.json";
		}

		file_put_contents($outputFile, $json);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'success'     => true,
				'file'        => $outputFile,
				'schemas'     => count($jumpstart->schemas),
				'collections' => count($jumpstart->collections['reserved']) + count($jumpstart->collections['custom']),
				'objects'     => count($jumpstart->objects),
				'templates'   => count($jumpstart->templates),
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		$output->writeln("<info>JumpStart exported to {$outputFile}</info>");
		$output->writeln('  Schemas:     ' . count($jumpstart->schemas));
		$output->writeln('  Collections: ' . (count($jumpstart->collections['reserved']) + count($jumpstart->collections['custom'])));
		$output->writeln('  Objects:     ' . count($jumpstart->objects));
		$output->writeln('  Templates:   ' . count($jumpstart->templates));

		return Command::SUCCESS;
	}
}
