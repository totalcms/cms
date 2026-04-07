<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Support\Version;

class UpdateApplyCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('update:apply')
			->setDescription('Download and apply an available update')
			->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$isJson = $this->isJson($input);

		// Check for update
		try {
			$updateInfo = $this->totalcms->updateChecker()->checkForUpdate(forceRefresh: true);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, 'Update check failed: ' . $e->getMessage());
		}

		if (!$updateInfo->available) {
			if ($isJson) {
				$output->writeln((string) json_encode(['success' => true, 'message' => 'Already up to date.']));
				return Command::SUCCESS;
			}
			$output->writeln('Total CMS ' . Version::number() . ' is already up to date.');
			return Command::SUCCESS;
		}

		if (!$isJson) {
			$output->writeln("Update available: <info>{$updateInfo->version}</info> ({$updateInfo->severity})");
			$output->writeln("Current version: " . Version::number());
			$output->writeln('');
		}

		// Confirm unless --force
		if (!$input->getOption('force') && !$isJson) {
			$output->writeln('<comment>This will update Total CMS and briefly put the site in maintenance mode.</comment>');
			$output->writeln('');

			/** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
			$helper   = $this->getHelper('question');
			$question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
				"Update to {$updateInfo->version}? [y/N] ",
				false
			);

			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('Update cancelled.');
				return Command::SUCCESS;
			}
		}

		// Download
		if (!$isJson) {
			$output->writeln("Downloading {$updateInfo->version}...");
		}

		try {
			$zipPath = $this->totalcms->updateDownloader()->download($updateInfo->version, $updateInfo->downloadUrl);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, 'Download failed: ' . $e->getMessage());
		}

		// Apply
		if (!$isJson) {
			$output->writeln('Applying update...');
		}

		try {
			$this->totalcms->updateApplier()->apply($zipPath, $updateInfo->version);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, 'Update failed: ' . $e->getMessage());
		}

		$this->totalcms->updateChecker()->clearCache();

		if ($isJson) {
			$output->writeln((string) json_encode([
				'success' => true,
				'message' => "Updated to {$updateInfo->version} successfully.",
				'version' => $updateInfo->version,
			], JSON_PRETTY_PRINT));
			return Command::SUCCESS;
		}

		$output->writeln('');
		$output->writeln("<info>Updated to {$updateInfo->version} successfully.</info>");
		return Command::SUCCESS;
	}
}
