<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ObjectListCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('object:list')
			->setDescription('List object IDs in a collection')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum results')
			->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Number of results to skip', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string) $input->getArgument('collection');
		$offset       = (int) $input->getOption('offset');
		$limit        = $input->getOption('limit');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		$index = $this->totalcms->indexReader()->fetchIndex($collectionId);
		$ids   = $index->objects->pluck('id')->filter()->values();

		if ($limit !== null) {
			$ids = $ids->slice($offset, (int) $limit)->values();
		} elseif ($offset > 0) {
			$ids = $ids->slice($offset)->values();
		}

		$data = $ids->toArray();

		return $this->outputData($input, $output, $data);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data === []) {
			$output->writeln('No objects found.');
			return;
		}

		foreach ($data as $id) {
			$output->writeln((string) $id);
		}

		$output->writeln('');
		$output->writeln(count($data) . ' object(s)');
	}
}
