<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;

class SchemaListCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('schema:list')
			->setDescription('List all schemas')
			->addOption('custom', null, InputOption::VALUE_NONE, 'Only show custom schemas')
			->addOption('reserved', null, InputOption::VALUE_NONE, 'Only show reserved schemas')
			->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by category');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$lister = $this->totalcms->schemaLister();

		if ($input->getOption('custom')) {
			$schemas = $lister->listCustomSchemas();
		} elseif ($input->getOption('reserved')) {
			$schemas = $lister->listReservedSchemas();
		} else {
			$schemas = $lister->listAllSchemas();
		}

		$category = $input->getOption('category');
		if (is_string($category)) {
			$schemas = array_filter($schemas, fn ($s) => $s->category === $category);
		}

		$data = array_map(fn ($s) => $s->toArray(), array_values($schemas));

		return $this->outputData($input, $output, $data);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data === []) {
			$output->writeln('No schemas found.');
			return;
		}

		$rows = [];
		foreach ($data as $schema) {
			$rows[] = [
				$schema['id'] ?? '',
				$schema['category'] ?? '',
				$schema['description'] ?? '',
			];
		}

		TableHelper::renderList($output, ['ID', 'Category', 'Description'], $rows);
	}
}
