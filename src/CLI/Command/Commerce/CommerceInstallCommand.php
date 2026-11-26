<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Commerce;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;

class CommerceInstallCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('commerce:install')
			->setDescription('Create the Commerce collections and emails that are missing')
			->setHelp(
				"Creates the products, orders, transactions, carts, subscriptions and discounts collections\n"
				. "and the receipt and failed-payment emails. Safe to run again: nothing that exists is changed.\n"
				. "Collection ids come from commerce.collections in your settings.\n"
				. 'Needs Total CMS Pro and the Commerce add-on.'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$container = $this->totalcms->container();

		// A site without Commerce gets no store collections it cannot use.
		$status = $container->get(CommerceGate::class)->status();
		if ($status !== CommerceGateStatus::Allowed) {
			return $this->outputError($input, $output, $status->refusal());
		}

		$report = $container->get(CommerceInstaller::class)->install();

		$this->outputData($input, $output, $report->toArray());

		return $report->ok() ? 0 : 1;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		foreach (['created' => 'Created', 'existing' => 'Already there'] as $key => $label) {
			if ($data[$key] !== []) {
				$output->writeln("<info>{$label}:</info> " . implode(', ', $data[$key]));
			}
		}
		foreach ($data['errors'] as $error) {
			$output->writeln("<error>{$error}</error>");
		}
		if ($data['created'] === [] && $data['errors'] === []) {
			$output->writeln('Nothing to do.');
		}
	}
}
