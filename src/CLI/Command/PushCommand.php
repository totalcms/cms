<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Config\SyncConfig;

class PushCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('push')
			->setDescription('Push schemas and templates to the production server')
			->addOption('schemas', null, InputOption::VALUE_REQUIRED, 'Comma-separated schema IDs to push (default: all)')
			->addOption('templates', null, InputOption::VALUE_REQUIRED, 'Comma-separated template IDs to push (default: all)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be pushed without sending');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$sync = new SyncConfig($this->totalcms->config->datadir);
		if (!$sync->isConfigured()) {
			return $this->outputError($input, $output, 'Sync not configured. Set the production URL and API key in Settings > Sync.');
		}

		$remote = $sync->getRemote();
		if ($remote === null) {
			return $this->outputError($input, $output, 'Sync not configured.');
		}

		$schemaFilter   = $this->parseFilter($input->getOption('schemas'));
		$templateFilter = $this->parseFilter($input->getOption('templates'));

		// Dry run — preview only, don't push
		if ($input->getOption('dry-run')) {
			$exporter = $this->totalcms->jumpStartExporter();
			$exporter->setMetadata('CLI Push', 'Dry run preview');
			$jumpstart = $exporter->exportSyncData($schemaFilter, $templateFilter);

			if ($jumpstart->isEmpty()) {
				$output->writeln('Nothing to push — no matching schemas or templates found.');

				return Command::SUCCESS;
			}

			return $this->dryRun($input, $output, $jumpstart->toArray(), $remote['url']);
		}

		// Actual push via shared service
		if (!$this->isJson($input)) {
			$output->writeln("Pushing to <info>{$remote['url']}</info>...");
		}

		try {
			$result = $this->totalcms->syncService()->push($remote['url'], $remote['key'], $schemaFilter, $templateFilter);
		} catch (\RuntimeException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln('');
		$output->writeln("<info>{$result['message']}</info>");
		$output->writeln("  Schemas: {$result['schemas']}, Templates: {$result['templates']}");

		return Command::SUCCESS;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function dryRun(InputInterface $input, OutputInterface $output, array $payload, string $url): int
	{
		$schemas   = $payload['schemas'] ?? [];
		$templates = $payload['templates'] ?? [];

		if ($this->isJson($input)) {
			$data = [
				'dry_run'   => true,
				'remote'    => $url,
				'schemas'   => array_map(fn (array $s): string => (string)($s['id'] ?? ''), $schemas),
				'templates' => array_map(fn (array $t): string => (string)($t['id'] ?? ''), $templates),
			];
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln("Dry run — would push to <info>{$url}</info>:");
		$output->writeln('');

		if ($schemas !== []) {
			$output->writeln('Schemas:');
			foreach ($schemas as $schema) {
				$output->writeln('  - ' . ($schema['id'] ?? 'unknown'));
			}
		}

		if ($templates !== []) {
			$output->writeln('Templates:');
			foreach ($templates as $template) {
				$output->writeln('  - ' . ($template['id'] ?? 'unknown'));
			}
		}

		return Command::SUCCESS;
	}

	/** @return list<string>|null */
	private function parseFilter(mixed $value): ?array
	{
		if (!is_string($value) || $value === '') {
			return null;
		}

		return array_map(trim(...), explode(',', $value));
	}
}
