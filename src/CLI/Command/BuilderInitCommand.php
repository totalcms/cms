<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Builder\Data\FrontendInstallOptions;
use TotalCMS\Domain\Builder\Service\BuilderFrontendInstaller;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Builder\Service\StarterService;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

class BuilderInitCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('builder:init')
			->setDescription('Initialize a Site Builder project from a starter template')
			->addArgument('starter', InputArgument::OPTIONAL, 'Starter template name (e.g., business, blog, portfolio, minimal)')
			->addOption('list', 'l', InputOption::VALUE_NONE, 'List available starters')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing templates')
			->addOption('frontend', null, InputOption::VALUE_NONE, 'Also install and build the Vite asset pipeline (equivalent to running tcms builder:frontend)')
			->addOption('no-build', null, InputOption::VALUE_NONE, 'With --frontend: install the files, but skip npm install and the build');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$service = $this->totalcms->container()->get(StarterService::class);
		$list    = (bool)$input->getOption('list');
		$starter = $input->getArgument('starter');

		// List mode or no argument given
		if ($list || !is_string($starter) || $starter === '') {
			return $this->listStarters($input, $output, $service);
		}

		// Scaffold
		if (!$this->isJson($input)) {
			$output->writeln("Scaffolding <info>{$starter}</info> starter...");
			$output->writeln('');
		}

		[$result, $frontendResult] = $this->scaffold($input, $output, $service, $starter);

		$succeeded = $result->success && ($frontendResult === null || $frontendResult->success);

		if ($this->isJson($input)) {
			$payload = $result->toArray();
			if ($frontendResult !== null) {
				$payload['frontend'] = $frontendResult->toArray();
			}
			$output->writeln((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $succeeded ? Command::SUCCESS : Command::FAILURE;
		}

		if (!$result->success) {
			$output->writeln('<error>' . $result->message . '</error>');

			return Command::FAILURE;
		}

		$output->writeln('<info>' . $result->message . '</info>');

		if ($frontendResult !== null) {
			$this->writeFrontendResult($output, $frontendResult);
		}

		$this->writeNextSteps($output, $frontendResult);

		return $succeeded ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * Scaffold the starter, and chain the frontend installer when asked for
	 * the greenfield happy path.
	 *
	 * @return array{OperationResult,OperationResult|null}
	 */
	private function scaffold(InputInterface $input, OutputInterface $output, StarterService $service, string $starter): array
	{
		$force = (bool)$input->getOption('force');

		if (!$input->getOption('frontend')) {
			return [$service->scaffold($starter, $force), null];
		}

		// Ask first, so a project that can't take the pipeline isn't left with
		// half a starter.
		$installer = $this->totalcms->container()->get(BuilderFrontendInstaller::class);
		$refused   = $installer->check();
		if ($refused instanceof OperationResult) {
			return [$refused, null];
		}

		$result = $service->scaffold($starter, $force, true);
		if (!$result->success) {
			return [$result, null];
		}

		return [$result, $installer->install(new FrontendInstallOptions(
			build: !$input->getOption('no-build'),
			onStep: $this->isJson($input) ? null : static function (string $command) use ($output): void {
				$output->writeln("→ {$command}");
			},
		))];
	}

	private function writeFrontendResult(OutputInterface $output, OperationResult $result): void
	{
		/** @var list<string> $notes */
		$notes = $result->data['notes'] ?? [];
		foreach ($notes as $note) {
			$output->writeln('<comment>!</comment> ' . OutputFormatter::escape($note));
		}

		$tag = $result->success ? 'info' : 'error';
		$output->writeln("<{$tag}>Frontend: " . OutputFormatter::escape($result->message) . "</{$tag}>");
	}

	private function writeNextSteps(OutputInterface $output, ?OperationResult $frontendResult): void
	{
		$output->writeln('');
		$output->writeln('Next steps:');
		// Git-managed projects keep templates in <project>/builder, not the datadir.
		$templateDir = $this->totalcms->container()->get(BuilderTemplatePaths::class)->writeTarget();
		$output->writeln('  1. Edit your templates in ' . $this->relativeToProject($templateDir) . '/');
		$output->writeln('  2. Add or edit pages in the admin under Site Builder');
		$output->writeln('  3. Visit your site — pages are served dynamically (no build step needed)');
		if ($frontendResult === null) {
			$output->writeln('  4. (optional) Add a frontend pipeline: <comment>tcms builder:frontend</comment>');
		} elseif (empty($frontendResult->data['built'])) {
			$output->writeln('  4. Build your CSS and JS: <comment>npm install && npm run build</comment>');
		} else {
			$output->writeln('  4. Edit <comment>css/style.css</comment>, then run <comment>npm run build</comment>');
		}
	}

	/** Show a path relative to the project root when it is inside it. */
	private function relativeToProject(string $path): string
	{
		$root = rtrim(PathResolver::projectRoot(), '/') . '/';

		return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
	}

	private function listStarters(InputInterface $input, OutputInterface $output, StarterService $service): int
	{
		$starters = $service->listStarters();

		if ($starters === []) {
			return $this->outputError($input, $output, 'No starter templates found');
		}

		if ($this->isJson($input)) {
			$data = array_map(fn ($s): array => $s->toArray(), $starters);
			$output->writeln((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$output->writeln('<info>Available starters:</info>');
		$output->writeln('');

		foreach ($starters as $starter) {
			$output->writeln('  <comment>' . str_pad($starter->name, 12) . '</comment> ' . $starter->description);
		}

		$output->writeln('');
		$output->writeln('Usage: <comment>tcms builder:init [starter-name]</comment>');

		return Command::SUCCESS;
	}
}
