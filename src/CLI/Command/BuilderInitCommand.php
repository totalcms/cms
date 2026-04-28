<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Builder\Service\StarterService;

class BuilderInitCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('builder:init')
			->setDescription('Initialize a Site Builder project from a starter template')
			->addArgument('starter', InputArgument::OPTIONAL, 'Starter template name (e.g., business, blog, portfolio, minimal)')
			->addOption('list', 'l', InputOption::VALUE_NONE, 'List available starters')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing templates');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$service = $this->totalcms->container()->get(StarterService::class);
		$list    = (bool)$input->getOption('list');
		$starter = $input->getArgument('starter');
		$force   = (bool)$input->getOption('force');

		// List mode or no argument given
		if ($list || !is_string($starter) || $starter === '') {
			return $this->listStarters($input, $output, $service);
		}

		// Scaffold
		if (!$this->isJson($input)) {
			$output->writeln("Scaffolding <info>{$starter}</info> starter...");
			$output->writeln('');
		}

		$result = $service->scaffold($starter, $force);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $result->success ? Command::SUCCESS : Command::FAILURE;
		}

		if ($result->success) {
			$output->writeln('<info>' . $result->message . '</info>');
			$output->writeln('');
			$output->writeln('Next steps:');
			$output->writeln('  1. Edit your templates in tcms-data/templates/');
			$output->writeln('  2. Add or edit pages in the admin under Collections > Pages');
			$output->writeln('  3. Run <comment>tcms builder:generate</comment> to write stubs to your docroot');

			return Command::SUCCESS;
		}

		$output->writeln('<error>' . $result->message . '</error>');

		return Command::FAILURE;
	}

	private function listStarters(InputInterface $input, OutputInterface $output, StarterService $service): int
	{
		$starters = $service->listStarters();

		if ($starters === []) {
			return $this->outputError($input, $output, 'No starter templates found');
		}

		if ($this->isJson($input)) {
			$data = array_map(fn ($s) => $s->toArray(), $starters);
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln('<info>Available starters:</info>');
		$output->writeln('');

		foreach ($starters as $starter) {
			$output->writeln('  <comment>' . str_pad($starter->name, 12) . '</comment> ' . $starter->description);
		}

		$output->writeln('');
		$output->writeln('Usage: <comment>tcms builder:init [starter-name]</comment>');

		return Command::SUCCESS;
	}
}
