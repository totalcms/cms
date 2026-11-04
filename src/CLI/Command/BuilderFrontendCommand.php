<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Builder\Data\FrontendInstallOptions;
use TotalCMS\Domain\Builder\Service\BuilderFrontendInstaller;

class BuilderFrontendCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('builder:frontend')
			->setDescription('Install and build the Vite asset pipeline at the project root')
			->addOption('no-build', null, InputOption::VALUE_NONE, 'Install the files, but skip npm install and the build')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, and change nothing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$installer = $this->totalcms->container()->get(BuilderFrontendInstaller::class);
		$json      = $this->isJson($input);

		$result = $installer->install(new FrontendInstallOptions(
			dryRun: (bool)$input->getOption('dry-run'),
			build: !$input->getOption('no-build'),
			onStep: $json ? null : static function (string $command) use ($output): void {
				$output->writeln("→ {$command}");
			},
		));

		if ($json) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $result->success ? Command::SUCCESS : Command::FAILURE;
		}

		/** @var list<string> $notes */
		$notes = $result->data['notes'] ?? [];
		foreach ($notes as $note) {
			$output->writeln('<comment>!</comment> ' . OutputFormatter::escape($note));
		}

		$tag = $result->success ? 'info' : 'error';
		$output->writeln("<{$tag}>" . OutputFormatter::escape($result->message) . "</{$tag}>");

		return $result->success ? Command::SUCCESS : Command::FAILURE;
	}
}
