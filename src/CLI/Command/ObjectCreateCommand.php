<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ObjectCreateCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('object:create')
			->setDescription('Create one object in a collection from a JSON file (use "-" to read stdin)')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON file holding one object, or "-" for stdin');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$filePath     = (string)$input->getArgument('file');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		if ($filePath === '-') {
			$content = stream_get_contents(STDIN);
		} else {
			if (!file_exists($filePath)) {
				return $this->outputError($input, $output, "File not found: {$filePath}");
			}
			$content = file_get_contents($filePath);
		}
		if ($content === false || trim($content) === '') {
			return $this->outputError($input, $output, 'No JSON input provided.');
		}

		$objectData = json_decode($content, true);
		if (!is_array($objectData)) {
			return $this->outputError($input, $output, 'Invalid JSON: expected a single object.');
		}
		if (array_is_list($objectData)) {
			return $this->outputError($input, $output, 'Input is a JSON array — object:create takes one object. Use collection:import for arrays.');
		}

		$objectId = (string)($objectData['id'] ?? '');
		if ($objectId !== '' && $this->totalcms->objectFetcher()->existsObject($collectionId, $objectId)) {
			return $this->outputError($input, $output, "Object '{$objectId}' already exists in '{$collectionId}'. Use the API or admin to update it.");
		}

		try {
			$object = $this->totalcms->objectSaver()->saveObject($collectionId, $objectData);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Object create failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($object->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln("<info>Object '{$object->id}' created in collection '{$collectionId}'.</info>");

		return Command::SUCCESS;
	}
}
