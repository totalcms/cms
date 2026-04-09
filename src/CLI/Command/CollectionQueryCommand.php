<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class CollectionQueryCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:query')
			->setDescription('Query a collection with filters and pagination')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID')
			->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Full-text search query')
			->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Contains filter (field:value)')
			->addOption('include', null, InputOption::VALUE_REQUIRED, 'Include filter (field:value,field:value)')
			->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Exclude filter (field:value,field:value)')
			->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort by property (prefix with - for descending)')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum results to return', '20')
			->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Number of results to skip', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('id');

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		// Build query params matching IndexQueryService expectations
		$params = [
			'limit'  => (string)$input->getOption('limit'),
			'offset' => (string)$input->getOption('offset'),
		];

		$search  = $input->getOption('search');
		$filter  = $input->getOption('filter');
		$include = $input->getOption('include');
		$exclude = $input->getOption('exclude');
		$sort    = $input->getOption('sort');

		if (is_string($search) && $search !== '') {
			$params['search'] = $search;
		}
		if (is_string($filter) && $filter !== '') {
			$params['filter'] = $filter;
		}
		if (is_string($include) && $include !== '') {
			$params['include'] = $include;
		}
		if (is_string($exclude) && $exclude !== '') {
			$params['exclude'] = $exclude;
		}
		if (is_string($sort) && $sort !== '') {
			$params['sort'] = $sort;
		}

		$result = $this->totalcms->indexQueryService()->query($collectionId, $params);

		$data = [
			'total'   => $result->total,
			'offset'  => $result->offset,
			'limit'   => $result->limit,
			'results' => $result->items,
		];

		return $this->outputData($input, $output, $data);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$results = $data['results'] ?? [];

		if ($results === []) {
			$output->writeln('No results found.');

			return;
		}

		// Build table from first result's keys
		$firstResult = reset($results);
		$headers     = array_keys(is_array($firstResult) ? $firstResult : []);

		// Limit to first 6 columns for readability
		$headers = array_slice($headers, 0, 6);

		$rows = [];
		foreach ($results as $row) {
			$rowData = [];
			foreach ($headers as $key) {
				$value = $row[$key] ?? '';
				if ($value === true || $value === 1) {
					$rowData[] = "\u{2714}";
				} elseif ($value === false) {
					$rowData[] = '';
				} elseif (is_array($value)) {
					$rowData[] = (string)json_encode($value, JSON_UNESCAPED_SLASHES);
				} else {
					$rowData[] = mb_strimwidth((string)$value, 0, 60, '...');
				}
			}
			$rows[] = $rowData;
		}

		TableHelper::renderList($output, $headers, $rows);
		$output->writeln('');
		$output->writeln("Showing {$data['offset']}-" . ($data['offset'] + count($results)) . " of {$data['total']} results");
	}
}
