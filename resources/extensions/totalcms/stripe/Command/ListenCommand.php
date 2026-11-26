<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Bundled\Stripe\StripeCli;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Support\Config;

/**
 * tcms stripe:listen [--url=…] [--skip-verify] [--check].
 *
 * Test payments on a site Stripe cannot reach, such as one on a `.test`
 * address. Stripe's own CLI holds a connection open to Stripe and forwards
 * each webhook event to the site. This starts it so that the two things that
 * go wrong by hand cannot:
 *
 *  - it listens with the test key the site itself charges with, so it hears
 *    the same account;
 *  - it saves the secret the Stripe CLI signs with as the site's Test
 *    Webhook Signing Secret, so the events it forwards are believed.
 *
 * It works with test keys only, and shows no key and no secret: the Stripe
 * CLI announces its signing secret when it starts, and that is hidden.
 */
final class ListenCommand extends Command
{
	private const EXTENSION = 'totalcms/stripe';

	private const SECRET = 'testWebhookSecret';

	private const WEBHOOK = '/commerce/webhook/stripe';

	/**
	 * Each service is resolved when the command runs: the container is
	 * still being built when the extension registers.
	 *
	 * @param \Closure(): ExtensionSettingsManager $settings
	 * @param \Closure(): Config                   $config
	 * @param \Closure(): CommerceSettings         $store
	 */
	public function __construct(
		private readonly \Closure $settings,
		private readonly \Closure $config,
		private readonly \Closure $store,
		private readonly StripeCli $cli,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this
			->setName('stripe:listen')
			->setDescription('Forward Stripe test payments to a local site, so its orders are marked paid')
			->setHelp(
				"Stripe tells a store that a payment went through by calling the store's webhook address.\n"
				. "It cannot call a site on your own computer, so the Stripe CLI forwards the events instead.\n\n"
				. "This command starts the Stripe CLI with the site's own test key, saves the secret it signs\n"
				. "with in the Stripe extension's settings, and forwards events until you stop it with Ctrl-C.\n\n"
				. 'It needs the Stripe CLI (https://docs.stripe.com/stripe-cli), and the extension in Test mode with a Test Secret Key.'
			)
			->addOption('url', null, InputOption::VALUE_REQUIRED, "The site's address, such as https://example.test or http://localhost:8000. Without it, the address the site was last opened at in a browser, which a site on localhost never has.")
			->addOption('skip-verify', null, InputOption::VALUE_NONE, "Do not check the site's certificate. For a local site whose certificate the Stripe CLI does not trust.")
			->addOption('check', null, InputOption::VALUE_NONE, 'Set everything up and report, without forwarding anything');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->cli->installed()) {
			return $this->refuse($output, 'The Stripe CLI is not installed, or is not on the path. Install it from https://docs.stripe.com/stripe-cli and run this again.');
		}

		$settings = ($this->settings)()->getSettings(self::EXTENSION);

		// A site in live mode charges real cards, and the events forwarded
		// here would be a sandbox's: nothing would match.
		if (($settings['mode'] ?? 'test') !== 'test') {
			return $this->refuse($output, 'The Stripe extension is in Live mode. Set Mode to Test in its settings: this command is for test payments only.');
		}

		$key = $this->text($settings['testSecretKey'] ?? '');
		if ($key === '') {
			return $this->refuse($output, "No Test Secret Key is saved in the Stripe extension's settings.");
		}
		// A live key in the test field would point the Stripe CLI at the live account.
		if (!str_starts_with($key, 'sk_test_') && !str_starts_with($key, 'rk_test_')) {
			return $this->refuse($output, 'The Test Secret Key is not a test key: it does not start with sk_test_. Nothing was started.');
		}
		$output->writeln('  <info>✓</info> Test secret key found');

		$asked   = $input->getOption('url');
		$address = $this->address(is_string($asked) ? $asked : null);
		if ($address === null) {
			return $this->refuse($output, is_string($asked)
				? "--url must be the site's web address, such as https://example.test"
				// The command line learns a site's address from a web request (see
				// SiteOrigin). It never learns one on localhost or an IP address,
				// and clearing the cache makes it forget the one it had.
				: "The command line does not know this site's address. Give it with --url, such as --url=https://example.test. (It learns the address when the site is opened in a browser, forgets it when the cache is cleared, and never learns one on localhost or an IP address.)");
		}
		$webhook = $address . self::WEBHOOK;

		$secret = $this->cli->signingSecret($key);
		if (preg_match('/^whsec_\S+\z/', $secret) !== 1) {
			return $this->refuse($output, 'The Stripe CLI gave no signing secret. Check that the Test Secret Key is still valid at Stripe. Running `stripe listen --print-secret` with that key shows what Stripe says.');
		}

		if ($this->text($settings[self::SECRET] ?? '') === $secret) {
			$output->writeln('  <info>✓</info> Webhook signing secret already saved');
		} else {
			($this->settings)()->saveSettings(self::EXTENSION, [self::SECRET => $secret] + $settings);
			$output->writeln('  <info>✓</info> Webhook signing secret saved as the Test Webhook Signing Secret');
		}

		// The shop offers Stripe only when it is one of the chosen providers.
		if (in_array('stripe', ($this->store)()->enabledProviders(), true)) {
			$output->writeln('  <info>✓</info> Stripe is a chosen payment provider');
		} else {
			$output->writeln('  <comment>!</comment> Stripe is not ticked in Settings → Commerce → Payment Providers, so the shop cannot take a payment yet.');
		}

		$output->writeln('');
		if ((bool)$input->getOption('check')) {
			$output->writeln("Ready. Run it without --check to forward events to {$webhook}");

			return Command::SUCCESS;
		}

		$output->writeln("Forwarding Stripe test events to {$webhook}");
		$output->writeln('Test card: 4242 4242 4242 4242, any future date, any CVC. Ctrl-C stops it.');
		$output->writeln('');

		// Raw, because the Stripe CLI's own lines hold `<--` and `-->`, which are not markup.
		return $this->cli->listen(
			$key,
			$webhook,
			(bool)$input->getOption('skip-verify'),
			static fn (string $text) => $output->write($text, false, OutputInterface::OUTPUT_RAW),
		);
	}

	/**
	 * Where the site is: what was asked for, or the address the command
	 * line knows the site by, with the folder it is installed in. Null when
	 * neither is a web address.
	 */
	private function address(?string $asked): ?string
	{
		$config  = ($this->config)();
		$address = rtrim($asked ?? rtrim($config->url, '/') . $config->api, '/');
		$scheme  = parse_url($address, PHP_URL_SCHEME);
		$host    = parse_url($address, PHP_URL_HOST);

		// A site that has only ever been run from the command line has no host: config/defaults.php calls it "unknown".
		return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== '' && $host !== 'unknown' ? $address : null;
	}

	private function refuse(OutputInterface $output, string $why): int
	{
		$output->writeln("<error>{$why}</error>");

		return Command::FAILURE;
	}

	private function text(mixed $value): string
	{
		return is_string($value) ? trim($value) : '';
	}
}
