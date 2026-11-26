<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Bundled\Stripe\StripeProvider;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * tcms stripe:tax
 *
 * Says whether Stripe Tax is ready on the account the site charges with.
 *
 * A store that leaves the tax to Stripe depends on things set up at Stripe,
 * not here: its tax settings, and a registration for each place tax is
 * collected. When they are missing a checkout still goes through, without
 * tax, so nothing on the site says anything is wrong. This asks Stripe and
 * says it in plain words. It reads; it changes nothing.
 */
final class TaxCommand extends Command
{
	/** What Stripe calls a missing setting, in words an owner knows. Any other is shown as Stripe names it. */
	private const MISSING = [
		'head_office' => 'your head office address',
	];

	/**
	 * @param \Closure(): CommerceSettings $store resolved when the command runs: the container is still being built when the extension registers
	 */
	public function __construct(
		private readonly StripeProvider $provider,
		private readonly \Closure $store,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('stripe:tax')
			->setDescription('Check that Stripe Tax is ready to work out the tax on orders')
			->setHelp(
				"With Tax Worked Out By set to \"The payment provider, when it can\" in Settings → Commerce, Stripe works out the tax\n"
				. "on each order and adds it when the buyer pays. That needs two things set up at Stripe: its tax settings\n"
				. "(your head office address), and a registration for each place you collect tax.\n\n"
				. "If they are missing a buyer can still pay, but is charged no tax, and nothing on the site says so.\n"
				. 'This asks Stripe, with the key of the mode the extension is in, and says what it found. It changes nothing.'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$mode = $this->provider->keyMode();
		if ($mode === '') {
			$output->writeln("<error>No Stripe secret key is saved for the mode the extension is in, so Stripe cannot be asked.</error>");

			return Command::FAILURE;
		}

		$status = $this->provider->taxStatus();
		if ($status['error'] !== '') {
			$output->writeln("<error>Stripe could not be asked ({$status['error']}). Check the secret key, and that this server can reach api.stripe.com.</error>");

			return Command::FAILURE;
		}

		$output->writeln("Stripe Tax, checked with the {$mode} key");

		if ($status['ready']) {
			$output->writeln('  <info>✓</info> Stripe Tax is set up');
		} else {
			$needs = implode(', ', array_map(static fn (string $field): string => self::MISSING[$field] ?? $field, $status['missing']));
			$output->writeln('  <comment>!</comment> Stripe Tax is not set up yet.' . ($needs !== '' ? " Stripe still needs: {$needs}." : '') . ' Finish it in the Stripe dashboard, under Tax.');
		}

		$places = $status['registrations'];
		if ($places > 0) {
			$output->writeln("  <info>✓</info> Tax is collected in {$places} " . ($places === 1 ? 'place' : 'places'));
		} else {
			$output->writeln('  <comment>!</comment> Tax is collected nowhere. Stripe works out tax only for places you are registered in, so every order would carry none. Add one in the Stripe dashboard, under Tax → Registrations.');
		}

		$ready = $status['ready'] && $places > 0;
		$store = ($this->store)();
		if ($store->taxMode() === TaxCalculator::NONE) {
			$output->writeln('  <comment>!</comment> Settings → Commerce → Tax is set to "No tax", so Stripe is not asked for tax on any order.');
		} elseif ($store->taxBy() !== TaxCalculator::BY_PROVIDER) {
			$output->writeln('  <comment>!</comment> Settings → Commerce → Tax Worked Out By is not "The payment provider, when it can", so Stripe is not asked for tax on any order.');
		} elseif ($ready) {
			$output->writeln('  <info>✓</info> Settings → Commerce leaves the tax to the payment provider');
		}

		$output->writeln('');
		$output->writeln($ready
			? 'Ready: Stripe works out the tax on an order paid with Stripe.'
			: 'Not ready: until the above is done, a buyer can still pay, but is charged no tax.');

		return $ready ? Command::SUCCESS : Command::FAILURE;
	}
}
