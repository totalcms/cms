<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Commerce;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Payment\OrderReconciler;
use TotalCMS\Domain\Commerce\Service\CommerceGate;

class CommerceReconcileCommand extends BaseCommand
{
	private const DEFAULT_MINUTES = 10;

	/** What each result reads as, in the list a person sees. */
	private const WORDS = [
		OrderReconciler::PAID    => 'paid',
		OrderReconciler::EXPIRED => 'its checkout ran out; given up on',
		OrderReconciler::REVIEW  => 'paid, but held for review: look at it in the admin',
		OrderReconciler::WAITING => 'still waiting',
		OrderReconciler::ERROR   => 'could not be checked (see the commerce log)',
	];

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('commerce:reconcile')
			->setDescription('Ask the payment provider about orders still waiting to be paid')
			->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Leave alone an order started less than this many minutes ago', (string)self::DEFAULT_MINUTES)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ask, and say what was found, but change nothing')
			->setHelp(
				"A store learns that an order was paid from the provider's webhook. If the webhook never arrives,\n"
				. "the buyer has paid and the order still says it is waiting. This asks the provider about every\n"
				. "order that has waited a while and records the answer, exactly as the webhook would have.\n\n"
				. "Safe to run as often as you like: an order is only ever paid once. Run it from cron, for example\n"
				. "every 15 minutes:\n\n"
				. "  */15 * * * * tcms commerce:reconcile\n\n"
				. 'It exits with 1 when an order could not be checked, so cron tells you.'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$container = $this->totalcms->container();

		$status = $container->get(CommerceGate::class)->status();
		if ($status !== CommerceGateStatus::Allowed) {
			return $this->outputError($input, $output, $status->refusal());
		}

		$minutes = $input->getOption('minutes');
		if (!is_string($minutes) || preg_match('/^\d{1,6}\z/', $minutes) !== 1) {
			return $this->outputError($input, $output, '--minutes takes a whole number of minutes.');
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$orders = $container->get(OrderReconciler::class)->run((int)$minutes, $dryRun);

		$this->outputData($input, $output, ['dryRun' => $dryRun, 'orders' => $orders]);

		return in_array(OrderReconciler::ERROR, array_column($orders, 'result'), true) ? 1 : 0;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data['dryRun']) {
			$output->writeln('<comment>Dry run: nothing was changed.</comment>');
		}
		if ($data['orders'] === []) {
			$output->writeln('Nothing to do: no order has been waiting that long.');

			return;
		}

		foreach ($data['orders'] as $order) {
			$words = self::WORDS[$order['result']] ?? $order['result'];
			$tag   = $order['result'] === OrderReconciler::ERROR ? 'error' : ($order['result'] === OrderReconciler::WAITING ? 'comment' : 'info');
			$output->writeln("Order {$order['number']}: <{$tag}>{$words}</{$tag}>");
		}
	}
}
