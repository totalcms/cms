<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SchemaLintCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('schema:lint')
			->setDescription('Lint stored schemas: structural errors plus agent-legibility warnings (missing help text)')
			->addArgument('id', InputArgument::OPTIONAL, 'Schema ID to lint (default: all custom schemas)')
			->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as failures');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id = $input->getArgument('id');

		if ($id !== null) {
			// A schema whose file is present but unparseable is not missing, and
			// saying so would be the least useful answer this command could give
			// — reporting bad schemas is its whole purpose. Let it through so the
			// linter names the file and the reason.
			$fetcher = $this->totalcms->schemaFetcher();
			if (!$fetcher->schemaExists((string)$id) && !$fetcher->schemaIsUnreadable((string)$id)) {
				return $this->outputError($input, $output, "Schema not found: {$id}");
			}
			$schemaIds = [(string)$id];
		} else {
			$schemaIds = array_map(
				fn (\TotalCMS\Domain\Schema\Data\SchemaData $schema): string => $schema->id,
				$this->totalcms->schemaLister()->listCustomSchemas()
			);
			if ($schemaIds === []) {
				return $this->outputError($input, $output, 'No custom schemas found. Pass a schema ID to lint a reserved schema.');
			}
		}

		$linter  = $this->totalcms->schemaLinter();
		$results = [];
		foreach ($schemaIds as $schemaId) {
			$results[$schemaId] = $linter->lint($schemaId);
		}

		$errorCount   = array_sum(array_map(fn (array $r): int => count($r['errors']), $results));
		$warningCount = array_sum(array_map(fn (array $r): int => count($r['warnings']), $results));
		$failed       = $errorCount > 0 || ($warningCount > 0 && $input->getOption('strict') === true);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'schemas'  => $results,
				'errors'   => $errorCount,
				'warnings' => $warningCount,
				'passed'   => !$failed,
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $failed ? Command::FAILURE : Command::SUCCESS;
		}

		foreach ($results as $schemaId => $result) {
			if ($result['errors'] === [] && $result['warnings'] === []) {
				$output->writeln("<info>✓ {$schemaId}</info>");
				continue;
			}
			$output->writeln("<comment>{$schemaId}</comment>");
			foreach ($result['errors'] as $message) {
				$output->writeln("  <error>error</error>    {$message}");
			}
			foreach ($result['warnings'] as $message) {
				$output->writeln("  <comment>warning</comment>  {$message}");
			}
		}

		$output->writeln('');
		$summary = count($schemaIds) . ' schema(s) linted: ' . "{$errorCount} error(s), {$warningCount} warning(s)";
		$output->writeln($failed ? "<error>{$summary}</error>" : "<info>{$summary}</info>");

		return $failed ? Command::FAILURE : Command::SUCCESS;
	}
}
