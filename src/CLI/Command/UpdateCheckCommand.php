<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Support\Version;

class UpdateCheckCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('update:check')
			->setDescription('Check for available updates');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		try {
			$updateInfo = $this->totalcms->updateChecker()->checkForUpdate(forceRefresh: true);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, 'Update check failed: ' . $e->getMessage());
		}

		if ($this->isJson($input)) {
			$data = array_merge(
				['current' => Version::number()],
				$updateInfo->toArray()
			);
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln('');

		if ($updateInfo->available) {
			$output->writeln("<info>Update available: {$updateInfo->version}</info> ({$updateInfo->severity})");
			$output->writeln('');

			$details = [
				'Current'  => Version::number(),
				'Latest'   => $updateInfo->version,
				'Released' => $updateInfo->releaseDate,
				'Severity' => $updateInfo->severity,
			];
			if ($updateInfo->updatesExpireDate !== null) {
				$details['Updates Expire'] = $updateInfo->updatesExpireDate;
			}
			TableHelper::renderKeyValue($output, $details);

			if ($updateInfo->changelog !== '') {
				$output->writeln('');
				$output->writeln('<info>Changelog:</info>');
				$output->writeln($updateInfo->changelog);
			}

			$output->writeln('');
			if (!$updateInfo->updatesValid) {
				$output->writeln('<comment>Your updates have expired. Renew your license to download this update.</comment>');
			} else {
				$output->writeln('Run <info>tcms update:apply</info> to update.');
			}
		} else {
			$output->writeln('Total CMS ' . Version::number() . ' is up to date.');
			if ($updateInfo->updatesExpireDate !== null) {
				$output->writeln("Updates valid until: {$updateInfo->updatesExpireDate}");
			}
		}

		$output->writeln('');

		return Command::SUCCESS;
	}
}
