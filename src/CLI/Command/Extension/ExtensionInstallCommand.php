<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Extension\Service\Install\ExtensionInstaller;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\Install\InstallPlan;
use TotalCMS\Domain\Extension\Service\Install\InstallResult;

/**
 * Install (or replace) a user extension from a zip. The same installer the
 * admin upload uses; nothing is enabled.
 */
class ExtensionInstallCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('extension:install')
			->setDescription('Install an extension from a zip into tcms-data/extensions')
			->addArgument('zip', InputArgument::REQUIRED, 'Path to the extension zip')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace an existing copy without asking');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$zip = (string)$input->getArgument('zip');
		if (!is_file($zip)) {
			return $this->outputError($input, $output, "File not found: {$zip}");
		}

		$installer = $this->totalcms->container()->get(ExtensionInstaller::class);

		try {
			$plan = $installer->plan($zip);
		} catch (InstallException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if (!$this->isJson($input)) {
			$this->describePlan($plan, $output);
		}

		if ($plan->isUpdate()) {
			$refused = $this->confirmOrRefuse($input, $output, sprintf(
				'Replace %s %s with %s? State and settings are kept. [y/N] ',
				$plan->manifest->id,
				$plan->existing?->version,
				$plan->manifest->version,
			));
			if ($refused !== null) {
				return $refused;
			}
		}

		try {
			$result = $installer->install($zip);
		} catch (InstallException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$this->reportResult($result, $output);

		return Command::SUCCESS;
	}

	/**
	 * Say what is about to happen before any prompt, so the operator confirms
	 * (or watches) a known id and version, not a file name.
	 */
	private function describePlan(InstallPlan $plan, OutputInterface $output): void
	{
		$output->writeln(sprintf('Extension: %s (%s)', $plan->manifest->id, $plan->manifest->name));
		$output->writeln(sprintf('Version:   %s', $plan->manifest->version));
		$output->writeln($plan->isUpdate()
			? sprintf('Action:    update of installed version %s', $plan->existing->version ?? '?')
			: 'Action:    fresh install (it will be installed disabled)');
	}

	private function reportResult(InstallResult $result, OutputInterface $output): void
	{
		if ($result->isUpdate) {
			$output->writeln(sprintf('<info>Updated %s %s → %s</info>', $result->id, $result->previousVersion ?? '?', $result->version));
		} else {
			$output->writeln(sprintf('<info>Installed %s %s</info>', $result->id, $result->version));
			$output->writeln("Enable it with: tcms extension:enable {$result->id}");
		}

		if ($result->findings > 0) {
			$output->writeln(sprintf('<comment>The source scan flagged %d pattern(s); review them before enabling.</comment>', $result->findings));
		}
		if ($result->disabled) {
			$output->writeln('<comment>The extension was enabled and has been disabled pending review of the new code.</comment>');
		}
		foreach ($result->warnings as $warning) {
			$output->writeln("<comment>Warning: {$warning}</comment>");
		}
	}
}
