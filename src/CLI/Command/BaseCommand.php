<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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
	 * Gate a destructive command on a yes/no question that defaults to no.
	 * `--force` and `--json` skip it. Returns null when the command may go
	 * ahead, or the exit code to return: SUCCESS after an operator answered
	 * no (`$declined` is printed), FAILURE when nobody can answer.
	 *
	 * A run with no terminal on stdin (cron, CI, a pipe) or with `-n` cannot
	 * answer. Symfony's question helper would then take the default — no —
	 * print "Aborted." and exit 0, so the calling script believes the
	 * command ran; or, on a pipe that never closes, wait on it forever. Both
	 * are worse than refusing with the way out named.
	 */
	protected function confirmOrRefuse(InputInterface $input, OutputInterface $output, string $question, string $declined = 'Aborted.'): ?int
	{
		if (($input->hasOption('force') && $input->getOption('force')) || $this->isJson($input)) {
			return null;
		}

		if (!self::canPrompt($input)) {
			return $this->outputError($input, $output, 'This command asks for confirmation and no one can answer: pass --force to run it without a prompt.');
		}

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		if ($helper->ask($input, $output, new ConfirmationQuestion($question, false))) {
			return null;
		}

		$output->writeln($declined);

		return Command::SUCCESS;
	}

	/**
	 * Whether a question can be answered: the run is interactive and stdin
	 * is a terminal. An input with its own stream (a test harness feeding
	 * answers) counts as answerable.
	 */
	private static function canPrompt(InputInterface $input): bool
	{
		if (!$input->isInteractive()) {
			return false;
		}

		if ($input instanceof StreamableInputInterface && is_resource($input->getStream())) {
			return true;
		}

		return defined('STDIN') && stream_isatty(STDIN);
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
