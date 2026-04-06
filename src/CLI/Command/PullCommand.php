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

class PullCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('pull')
			->setDescription('Pull schemas and templates from the production server')
			->addOption('schemas', null, InputOption::VALUE_REQUIRED, 'Comma-separated schema IDs to pull (default: all)')
			->addOption('templates', null, InputOption::VALUE_REQUIRED, 'Comma-separated template IDs to pull (default: all)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be pulled without applying');
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

		// Fetch JumpStart payload from remote
		if (!$this->isJson($input)) {
			$output->writeln("Pulling from <info>{$url}</info>...");
		}

		try {
			$client   = new Client();
			$response = $client->request('GET', $url . '/export/jumpstart?mode=sync', [
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
			return $this->outputError($input, $output, 'Pull failed: ' . $e->getMessage());
		}

		$statusCode = $response->getStatusCode();
		$body       = (string) $response->getBody();
		$payload    = json_decode($body, true);

		if ($statusCode >= 400 || !is_array($payload)) {
			$error = is_array($payload) ? ($payload['error'] ?? $body) : $body;
			return $this->outputError($input, $output, "Pull failed (HTTP {$statusCode}): {$error}");
		}

		// Apply selective filters
		$schemaFilter   = $this->parseFilter($input->getOption('schemas'));
		$templateFilter = $this->parseFilter($input->getOption('templates'));

		$schemas   = $payload['schemas'] ?? [];
		$templates = $payload['templates'] ?? [];

		if ($schemaFilter !== null) {
			$schemas = array_values(array_filter($schemas, fn (array $s): bool => in_array($s['id'] ?? '', $schemaFilter, true)));
			$payload['schemas'] = $schemas;
		}
		if ($templateFilter !== null) {
			$templates = array_values(array_filter($templates, fn (array $t): bool => in_array($t['id'] ?? '', $templateFilter, true)));
			$payload['templates'] = $templates;
		}

		if ($schemas === [] && $templates === []) {
			if ($this->isJson($input)) {
				$output->writeln((string) json_encode(['success' => true, 'schemas_pulled' => 0, 'templates_pulled' => 0]));
				return Command::SUCCESS;
			}
			$output->writeln('Nothing to pull — no matching schemas or templates found.');
			return Command::SUCCESS;
		}

		// Dry run
		if ($input->getOption('dry-run')) {
			return $this->dryRun($input, $output, $payload, $url);
		}

		// Import locally
		if (!$this->isJson($input)) {
			$output->writeln("  " . count($schemas) . " schema(s), " . count($templates) . " template(s)");
			$output->writeln('Importing...');
		}

		$result = $this->totalcms->jumpStartImporter()->importFromDefinition($payload);

		if ($this->isJson($input)) {
			$data = [
				'success'          => true,
				'remote'           => $url,
				'schemas_pulled'   => count($schemas),
				'templates_pulled' => count($templates),
				'import_results'   => $result,
			];
			$output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return Command::SUCCESS;
		}

		$output->writeln('');
		$output->writeln('<info>Pull complete.</info>');

		$summary = $result['summary'];
		foreach ($summary as $k => $value) {
			$output->writeln("  {$k}: {$value}");
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
				'schemas'   => is_array($schemas) ? array_map(fn (array $s): string => (string) ($s['id'] ?? ''), $schemas) : [],
				'templates' => is_array($templates) ? array_map(fn (array $t): string => (string) ($t['id'] ?? ''), $templates) : [],
			];
			$output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return Command::SUCCESS;
		}

		$output->writeln("Dry run — would pull from <info>{$url}</info>:");
		$output->writeln('');

		if (is_array($schemas) && $schemas !== []) {
			$output->writeln('Schemas:');
			foreach ($schemas as $schema) {
				$output->writeln('  - ' . ($schema['id'] ?? 'unknown'));
			}
		}

		if (is_array($templates) && $templates !== []) {
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
