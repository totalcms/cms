<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Builder\Service\BuilderFrontendInstaller;

class BuilderFrontendCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('builder:frontend')
			->setDescription('Install a Vite-based frontend asset pipeline scaffold into the project')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files in frontend/');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$installer = $this->totalcms->container()->get(BuilderFrontendInstaller::class);
		$force     = (bool)$input->getOption('force');

		if (!$this->isJson($input)) {
			$output->writeln('Installing frontend scaffold...');
			$output->writeln('');
		}

		$result = $installer->install($force);

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $result->success ? Command::SUCCESS : Command::FAILURE;
		}

		if (!$result->success) {
			$output->writeln('<error>' . $result->message . '</error>');

			return Command::FAILURE;
		}

		$output->writeln('<info>' . $result->message . '</info>');

		$target = (string)($result->data['target'] ?? '');
		if ($target !== '') {
			$output->writeln('');
			$output->writeln('Next steps:');
			$output->writeln("  1. <comment>cd {$target}</comment>");
			$output->writeln('  2. <comment>npm install</comment>');
			$output->writeln('  3. <comment>npm run build</comment>');
			$output->writeln('  4. Reference assets in your layout: <comment>{{ cms.builder.css(\'src/css/style.css\') }}</comment>');
		}

		return Command::SUCCESS;
	}
}
