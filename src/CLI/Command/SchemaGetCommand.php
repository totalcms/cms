<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class SchemaGetCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('schema:get')
			->setDescription('Show schema details')
			->addArgument('id', InputArgument::REQUIRED, 'Schema ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id      = (string)$input->getArgument('id');
		$fetcher = $this->totalcms->schemaFetcher();

		if (!$fetcher->schemaExists($id)) {
			return $this->outputError($input, $output, "Schema '{$id}' not found.");
		}

		$schema = $fetcher->fetchSchema($id);

		return $this->outputData($input, $output, $schema->toArray());
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln('');
		$output->writeln("<info>Schema: {$data['id']}</info>");
		$output->writeln('');

		$meta = [
			'Description' => $data['description'] ?? '',
			'Category'    => $data['category'] ?? '',
		];

		if (!empty($data['inheritFrom'])) {
			$meta['Inherits From'] = implode(', ', $data['inheritFrom']);
		}

		TableHelper::renderKeyValue($output, $meta);

		// Show properties
		$properties = $data['properties'] ?? [];
		if ($properties !== []) {
			$output->writeln('');
			$output->writeln('<info>Properties:</info>');

			$rows = [];
			foreach ($properties as $name => $prop) {
				$type = $prop['type'] ?? '';
				if ($type === '' && isset($prop['$ref']) && is_string($prop['$ref'])) {
					$type = basename($prop['$ref'], '.json');
				}
				$field    = (string)($prop['field'] ?? '');
				$required = in_array($name, $data['required'] ?? [], true) ? 'Yes' : '';
				$indexed  = in_array($name, $data['index'] ?? [], true) ? 'Yes' : '';
				$rows[]   = [(string)$name, (string)$type, $field, $required, $indexed];
			}

			TableHelper::renderList($output, ['Name', 'Type', 'Field', 'Required', 'Indexed'], $rows);
		}

		$output->writeln('');
	}
}
