<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateRollbackCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('update:rollback')
			->setDescription('Roll back to the previous version')
			->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$isJson = $this->isJson($input);

		if (!$input->getOption('force') && !$isJson) {
			$output->writeln('<comment>This will restore the previous version of Total CMS.</comment>');
			$output->writeln('');

			/** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
			$helper   = $this->getHelper('question');
			$question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
				'Roll back to previous version? [y/N] ',
				false
			);

			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('Rollback cancelled.');
				return Command::SUCCESS;
			}
		}

		try {
			$this->totalcms->updateApplier()->rollback();
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, 'Rollback failed: ' . $e->getMessage());
		}

		if ($isJson) {
			$output->writeln((string) json_encode([
				'success' => true,
				'message' => 'Rollback complete.',
			], JSON_PRETTY_PRINT));
			return Command::SUCCESS;
		}

		$output->writeln('<info>Rollback complete.</info>');
		return Command::SUCCESS;
	}
}
