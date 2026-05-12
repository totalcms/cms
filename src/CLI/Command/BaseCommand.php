<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\TotalCMS;

abstract class BaseCommand extends Command
{
	public function __construct(
		protected readonly TotalCMS $totalcms,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
	}

	protected function isJson(InputInterface $input): bool
	{
		return (bool)$input->getOption('json');
	}

	/**
	 * Write structured data as JSON (if --json) or delegate to renderHuman().
	 *
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function outputData(InputInterface $input, OutputInterface $output, array $data): int
	{
		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$this->renderHuman($input, $output, $data);

		return Command::SUCCESS;
	}

	/**
	 * Override in subclasses for human-readable output.
	 *
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Write an error message to stderr.
	 */
	protected function outputError(InputInterface $input, OutputInterface $output, string $message): int
	{
		$stderr = $output instanceof ConsoleOutputInterface
			? $output->getErrorOutput()
			: $output;

		if ($this->isJson($input)) {
			$stderr->writeln((string)json_encode(['error' => $message]));
		} else {
			$stderr->writeln("<error>{$message}</error>");
		}

		return Command::FAILURE;
	}
}
