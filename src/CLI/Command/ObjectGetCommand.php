<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class ObjectGetCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('object:get')
			->setDescription('Fetch a single object')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('id', InputArgument::REQUIRED, 'Object ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('id');

		if (!$this->totalcms->objectFetcher()->existsObject($collectionId, $objectId)) {
			return $this->outputError($input, $output, "Object '{$objectId}' not found in collection '{$collectionId}'.");
		}

		$object = $this->totalcms->objectFetcher()->fetchObject($collectionId, $objectId);

		return $this->outputData($input, $output, $object->toArray());
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$id = $data['id'] ?? 'unknown';
		$output->writeln('');
		$output->writeln("<info>Object: {$id}</info>");
		$output->writeln('');

		$display = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$display[(string)$key] = (string)json_encode($value, JSON_UNESCAPED_SLASHES);
			} else {
				$strValue              = (string)$value;
				$display[(string)$key] = mb_strimwidth($strValue, 0, 120, '...');
			}
		}

		TableHelper::renderKeyValue($output, $display);
		$output->writeln('');
	}
}
