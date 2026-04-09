<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class CollectionListCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:list')
			->setDescription('List all collections')
			->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Filter by schema')
			->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$lister = $this->totalcms->collectionLister();

		$schema = $input->getOption('schema');
		if (is_string($schema)) {
			$collections = $lister->listCollectionsWithSchema($schema);
		} else {
			$collections = $lister->listAllCollections();
		}

		$category = $input->getOption('category');
		if (is_string($category)) {
			$collections = array_filter($collections, fn ($c) => $c->category === $category);
		}

		$data = array_map(fn ($c) => $c->toArray(), array_values($collections));

		return $this->outputData($input, $output, $data);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data === []) {
			$output->writeln('No collections found.');

			return;
		}

		$rows = [];
		foreach ($data as $col) {
			$rows[] = [
				$col['id'] ?? '',
				$col['name'] ?? '',
				$col['schema'] ?? '',
				(string)($col['totalObjects'] ?? ''),
			];
		}

		TableHelper::renderList($output, ['ID', 'Name', 'Schema', 'Objects'], $rows);
	}
}
