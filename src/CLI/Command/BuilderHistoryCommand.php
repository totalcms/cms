<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Domain\Template\Service\TemplateSnapshotService;

class BuilderHistoryCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('builder:history')
			->setDescription('List or restore snapshot versions of a builder template')
			->addArgument('path', InputArgument::REQUIRED, 'Template path (e.g. "pages/about")')
			->addOption('restore', null, InputOption::VALUE_REQUIRED, 'Restore the snapshot at this UNIX timestamp')
			->addOption('show', null, InputOption::VALUE_REQUIRED, 'Print the contents of the snapshot at this UNIX timestamp');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = (string)$input->getArgument('path');
		[$folder, $id] = TemplateRepository::parsePath($path);

		$container = $this->totalcms->container();
		$snapshots = $container->get(TemplateSnapshotService::class);

		$restoreOpt = $input->getOption('restore');
		if (is_string($restoreOpt) && $restoreOpt !== '') {
			return $this->handleRestore($input, $output, $snapshots, $container->get(TemplateSaver::class), $id, $folder, (int)$restoreOpt);
		}

		$showOpt = $input->getOption('show');
		if (is_string($showOpt) && $showOpt !== '') {
			return $this->handleShow($input, $output, $snapshots, $id, $folder, (int)$showOpt);
		}

		return $this->handleList($input, $output, $snapshots, $id, $folder, $path);
	}

	private function handleList(
		InputInterface $input,
		OutputInterface $output,
		TemplateSnapshotService $snapshots,
		string $id,
		?string $folder,
		string $displayPath,
	): int {
		$versions = $snapshots->listVersions($id, $folder);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'path'     => $displayPath,
				'versions' => $versions,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		if ($versions === []) {
			$output->writeln("No history for <info>{$displayPath}</info>.");

			return Command::SUCCESS;
		}

		$output->writeln("Versions for <info>{$displayPath}</info>:");
		$output->writeln('');

		$now  = time();
		$rows = [];
		foreach ($versions as $ts) {
			$rows[] = [
				(string)$ts,
				date('Y-m-d H:i:s', $ts),
				$this->humanRelative($now - $ts),
			];
		}

		TableHelper::renderList($output, ['Timestamp', 'Date', 'Age'], $rows);

		$output->writeln('');
		$output->writeln("Restore with: <comment>tcms builder:history {$displayPath} --restore=<timestamp></comment>");

		return Command::SUCCESS;
	}

	private function handleShow(
		InputInterface $input,
		OutputInterface $output,
		TemplateSnapshotService $snapshots,
		string $id,
		?string $folder,
		int $timestamp,
	): int {
		try {
			$contents = $snapshots->readVersion($id, $folder, $timestamp);
		} catch (\DomainException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		$output->write($contents);

		return Command::SUCCESS;
	}

	private function handleRestore(
		InputInterface $input,
		OutputInterface $output,
		TemplateSnapshotService $snapshots,
		TemplateSaver $saver,
		string $id,
		?string $folder,
		int $timestamp,
	): int {
		try {
			$contents = $snapshots->readVersion($id, $folder, $timestamp);
		} catch (\DomainException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		// Saving captures a fresh snapshot of the *current* contents before
		// overwriting, so the restore itself is reversible.
		$saver->saveTemplate($id, $contents, $folder);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'restored'  => true,
				'timestamp' => $timestamp,
			], JSON_PRETTY_PRINT));
		} else {
			$output->writeln("<info>Restored</info> to version " . date('Y-m-d H:i:s', $timestamp));
		}

		return Command::SUCCESS;
	}

	private function humanRelative(int $seconds): string
	{
		if ($seconds < 60) {
			return "{$seconds}s ago";
		}
		if ($seconds < 3600) {
			$m = (int)floor($seconds / 60);

			return "{$m}m ago";
		}
		if ($seconds < 86400) {
			$h = (int)floor($seconds / 3600);

			return "{$h}h ago";
		}
		$d = (int)floor($seconds / 86400);

		return "{$d}d ago";
	}
}
