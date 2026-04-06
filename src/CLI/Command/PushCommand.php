<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
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

		$url = $remote['url'];
		$key = $remote['key'];

		// Parse selective filters
		$schemaFilter   = $this->parseFilter($input->getOption('schemas'));
		$templateFilter = $this->parseFilter($input->getOption('templates'));

		// Build the JumpStart payload
		$exporter = $this->totalcms->jumpStartExporter();
		$exporter->setMetadata('CLI Push', 'Schemas and templates pushed via tcms push');
		$jumpstart = $exporter->exportSyncData($schemaFilter, $templateFilter);

		$schemaCount   = count($jumpstart->schemas);
		$templateCount = count($jumpstart->templates);

		if ($jumpstart->isEmpty()) {
			if ($this->isJson($input)) {
				$output->writeln((string) json_encode(['success' => true, 'schemas_pushed' => 0, 'templates_pushed' => 0]));
				return Command::SUCCESS;
			}
			$output->writeln('Nothing to push — no matching schemas or templates found.');
			return Command::SUCCESS;
		}

		// Dry run
		if ($input->getOption('dry-run')) {
			return $this->dryRun($input, $output, $jumpstart->toArray(), $url);
		}

		// Push
		if (!$this->isJson($input)) {
			$output->writeln("Pushing to <info>{$url}</info>...");
			$output->writeln("  {$schemaCount} schema(s), {$templateCount} template(s)");
		}

		try {
			$client   = new Client();
			$response = $client->request('POST', $url . '/import/jumpstart', [
				RequestOptions::JSON    => $jumpstart->toArray(),
				RequestOptions::HEADERS => [
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
					'User-Agent'    => 'TotalCMS-CLI/1.0',
				],
				RequestOptions::TIMEOUT         => 60,
				RequestOptions::CONNECT_TIMEOUT => 10,
				RequestOptions::HTTP_ERRORS     => false,
			]);
		} catch (GuzzleException $e) {
			return $this->outputError($input, $output, 'Push failed: ' . $e->getMessage());
		}

		$statusCode = $response->getStatusCode();
		$body       = (string) $response->getBody();
		$result     = json_decode($body, true);

		if ($statusCode >= 400 || !is_array($result)) {
			$error = is_array($result) ? ($result['error'] ?? $body) : $body;
			return $this->outputError($input, $output, "Push failed (HTTP {$statusCode}): {$error}");
		}

		if ($this->isJson($input)) {
			$data = [
				'success'          => true,
				'remote'           => $url,
				'schemas_pushed'   => $schemaCount,
				'templates_pushed' => $templateCount,
				'response'         => $result,
			];
			$output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return Command::SUCCESS;
		}

		$output->writeln('');
		$output->writeln('<info>Push complete.</info>');
		$summary = $result['summary'] ?? [];
		if (is_array($summary)) {
			foreach ($summary as $summaryKey => $value) {
				$output->writeln("  {$summaryKey}: {$value}");
			}
		}

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
				'schemas'   => array_map(fn (array $s): string => (string) ($s['id'] ?? ''), $schemas),
				'templates' => array_map(fn (array $t): string => (string) ($t['id'] ?? ''), $templates),
			];
			$output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

		return array_map('trim', explode(',', $value));
	}
}
