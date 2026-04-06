<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class CollectionGetCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:get')
			->setDescription('Show collection metadata')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id         = (string) $input->getArgument('id');
		$collection = $this->totalcms->collectionFetcher()->fetchCollection($id);

		if ($collection === null) {
			return $this->outputError($input, $output, "Collection '{$id}' not found.");
		}

		return $this->outputData($input, $output, $collection->toArray());
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln('');
		$output->writeln("<info>Collection: {$data['id']}</info>");
		$output->writeln('');

		TableHelper::renderKeyValue($output, [
			'Name'          => $data['name'] ?? '',
			'Schema'        => $data['schema'] ?? '',
			'Category'      => $data['category'] ?? '',
			'Description'   => $data['description'] ?? '',
			'Objects'       => (string) ($data['totalObjects'] ?? ''),
			'Sort By'       => $data['sortBy'] ?? '',
			'Reverse Sort'  => ($data['reverseSort'] ?? false) ? 'Yes' : 'No',
			'Last Updated'  => $data['lastUpdated'] ?? '',
		]);

		$output->writeln('');
	}
}
