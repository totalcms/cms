<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\Bundled\Stripe\Command\ListenCommand;
use TotalCMS\Bundled\Stripe\StripeCli;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Support\Config;

/*
 * `tcms stripe:listen`: test payments on a site Stripe cannot reach.
 *
 * Stripe's own CLI holds a connection open to Stripe and forwards each
 * webhook event to the site. The command starts it against the sandbox the
 * site charges, and saves the secret it signs with, so the two things that
 * go wrong by hand (the wrong account, the wrong secret) cannot. Nothing
 * here runs the Stripe CLI: a stand-in records what it was asked to do.
 */

const STRIPE_LISTEN_SETTINGS = [
	'mode'               => 'test',
	'testPublishableKey' => 'pk_test_public',
	'testSecretKey'      => 'sk_test_the_key',
	'testWebhookSecret'  => '',
	'liveSecretKey'      => '',
	'liveWebhookSecret'  => 'whsec_live_untouched',
];

/** Boot a store with the Stripe extension enabled and these settings. */
function bootSiteForStripeListen(array $settings = STRIPE_LISTEN_SETTINGS, bool $commands = true): void
{
	bootCommerceStore(true, function () use ($settings, $commands): void {
		$system = cmsDataDir() . '.system';
		is_dir($system . '/extension-settings/totalcms') || mkdir($system . '/extension-settings/totalcms', 0755, true);
		file_put_contents($system . '/extensions.json', json_encode(['totalcms/stripe' => ['enabled' => true, 'permissions' => ['commerce:payments' => true, 'cli:commands' => $commands]]]));
		file_put_contents($system . '/extension-settings/totalcms/stripe.json', json_encode($settings));
	});

	$c                = test()->commerceApp->getContainer();
	$config           = $c->get(Config::class);
	$config->commerce = ['providers' => ['stripe']] + $config->commerce;
	test()->c         = $c;
}

/**
 * A Stripe CLI that runs nothing and remembers what it was asked.
 *
 * @param array{installed?: bool, secret?: string, exit?: int, prints?: string} $with
 */
function stripeCliStandIn(array $with = []): StripeCli
{
	return new class($with) extends StripeCli {
		/** @var list<string> */
		public array $askedWith = [];

		/** @var list<array{string, string, bool}> */
		public array $listened = [];

		/** @param array{installed?: bool, secret?: string, exit?: int, prints?: string} $with */
		public function __construct(private array $with)
		{
		}

		public function installed(): bool
		{
			return $this->with['installed'] ?? true;
		}

		public function signingSecret(string $apiKey): string
		{
			$this->askedWith[] = $apiKey;

			return $this->with['secret'] ?? 'whsec_from_the_cli';
		}

		public function listen(string $apiKey, string $forwardTo, bool $skipVerify, Closure $print): int
		{
			$this->listened[] = [$apiKey, $forwardTo, $skipVerify];
			$print($this->with['prints'] ?? '');

			return $this->with['exit'] ?? 0;
		}
	};
}

/** @param array<string,mixed> $input */
function stripeListen(StripeCli $cli, array $input = ['--url' => 'https://shop.test']): CommandTester
{
	$c      = test()->c;
	$tester = new CommandTester(new ListenCommand(
		fn (): ExtensionSettingsManager => $c->get(ExtensionSettingsManager::class),
		fn (): Config => $c->get(Config::class),
		fn (): CommerceSettings => $c->get(CommerceSettings::class),
		$cli,
	));
	$tester->execute($input);

	return $tester;
}

/** @return array<string,mixed> the Stripe settings as they are on disk now */
function stripeSettingsOnDisk(): array
{
	return json_decode((string)file_get_contents(cmsDataDir() . '.system/extension-settings/totalcms/stripe.json'), true);
}

describe('a site ready to test with', function (): void {
	beforeEach(fn () => bootSiteForStripeListen());

	test('it listens with the site\'s own test key, and forwards to the site\'s webhook address', function (): void {
		$cli    = stripeCliStandIn();
		$tester = stripeListen($cli);

		expect($tester->getStatusCode())->toBe(Command::SUCCESS)
			->and($cli->askedWith)->toBe(['sk_test_the_key'])
			->and($cli->listened)->toBe([['sk_test_the_key', 'https://shop.test/commerce/webhook/stripe', false]]);
	});

	test('the secret the Stripe CLI signs with is saved, and nothing else in the settings changes', function (): void {
		stripeListen(stripeCliStandIn());

		expect(stripeSettingsOnDisk())->toBe(['testWebhookSecret' => 'whsec_from_the_cli'] + STRIPE_LISTEN_SETTINGS);
	});

	test('a secret that is already the right one is said to be, and left', function (): void {
		stripeListen(stripeCliStandIn());

		$tester = stripeListen(stripeCliStandIn());

		expect($tester->getDisplay())->toContain('already saved')
			->and(stripeSettingsOnDisk()['testWebhookSecret'])->toBe('whsec_from_the_cli');
	});

	test('no key and no secret is ever printed', function (): void {
		$tester = stripeListen(stripeCliStandIn());

		expect($tester->getDisplay())->not->toContain('sk_test_the_key', 'whsec_from_the_cli', 'whsec_live_untouched');
	});

	test('--check sets everything up and forwards nothing', function (): void {
		$cli    = stripeCliStandIn();
		$tester = stripeListen($cli, ['--url' => 'https://shop.test', '--check' => true]);

		expect($tester->getStatusCode())->toBe(Command::SUCCESS)
			->and($cli->listened)->toBe([])
			->and(stripeSettingsOnDisk()['testWebhookSecret'])->toBe('whsec_from_the_cli')
			->and($tester->getDisplay())->toContain('https://shop.test/commerce/webhook/stripe');
	});

	test('--skip-verify is passed on, for a local site whose certificate the Stripe CLI does not trust', function (): void {
		$cli = stripeCliStandIn();
		stripeListen($cli, ['--url' => 'https://shop.test', '--skip-verify' => true]);

		expect($cli->listened[0][2])->toBeTrue();
	});

	test('how the Stripe CLI ended is how the command ends', function (): void {
		expect(stripeListen(stripeCliStandIn(['exit' => 3]))->getStatusCode())->toBe(3);
	});

	test('what the Stripe CLI prints is shown as it is, arrows and all', function (): void {
		$tester = stripeListen(stripeCliStandIn(['prints' => "2026-11-23 12:45:12  <--  [200] POST https://shop.test/commerce/webhook/stripe <info>\n"]));

		expect($tester->getDisplay())->toContain('<--  [200] POST https://shop.test/commerce/webhook/stripe <info>');
	});

	test('the test card is shown before it starts listening', function (): void {
		expect(stripeListen(stripeCliStandIn())->getDisplay())->toContain('4242 4242 4242 4242');
	});
});

describe('where the site is', function (): void {
	beforeEach(fn () => bootSiteForStripeListen());

	test('with no --url it is the address the command line knows the site by, with its folder', function (): void {
		$config      = $this->c->get(Config::class);
		$config->url = 'https://remembered.test';
		$config->api = '/cms';
		$cli         = stripeCliStandIn();

		stripeListen($cli, []);

		expect($cli->listened[0][1])->toBe('https://remembered.test/cms/commerce/webhook/stripe');
	});

	test('a slash at the end of --url does not double up', function (): void {
		$cli = stripeCliStandIn();
		stripeListen($cli, ['--url' => 'https://shop.test/cms/']);

		expect($cli->listened[0][1])->toBe('https://shop.test/cms/commerce/webhook/stripe');
	});

	// The command line learns the address from a web request. It never learns
	// one on localhost, and a cache clear makes it forget the one it had.
	test('a site the command line has no address for is asked for one', function (): void {
		$config      = $this->c->get(Config::class);
		$config->url = 'http://unknown';
		$cli         = stripeCliStandIn();

		$tester = stripeListen($cli, []);

		expect($tester->getStatusCode())->toBe(Command::FAILURE)
			->and($tester->getDisplay())->toContain('--url')
			->and($cli->listened)->toBe([]);
	});

	test('--url must be a web address', function (string $url): void {
		$cli    = stripeCliStandIn();
		$tester = stripeListen($cli, ['--url' => $url]);

		expect($tester->getStatusCode())->toBe(Command::FAILURE)
			->and($cli->listened)->toBe([]);
	})->with(['shop.test', 'ftp://shop.test', 'javascript:alert(1)', '']);
});

describe('what it refuses', function (): void {
	test('it starts nothing, saves nothing, and says why', function (array $settings, array $cli, string $says): void {
		bootSiteForStripeListen($settings + STRIPE_LISTEN_SETTINGS);
		$standIn = stripeCliStandIn($cli);

		$tester = stripeListen($standIn);

		expect($tester->getStatusCode())->toBe(Command::FAILURE)
			->and($standIn->listened)->toBe([])
			->and(stripeSettingsOnDisk()['testWebhookSecret'])->toBe('')
			->and($tester->getDisplay())->toContain($says);
	})->with([
		// A site in live mode charges real cards; forwarded sandbox events would match nothing.
		'live mode'                    => [['mode' => 'live'], [], 'Test'],
		'no test key'                  => [['testSecretKey' => ''], [], 'Test Secret Key'],
		// A live key in the test field would point the Stripe CLI at the live account.
		'a live key in the test field' => [['testSecretKey' => 'sk_live_real_money'], [], 'not a test key'],
		'no Stripe CLI'                => [[], ['installed' => false], 'Stripe CLI'],
		'a CLI that gives no secret'   => [[], ['secret' => ''], 'signing secret'],
		'a CLI that gives nonsense'    => [[], ['secret' => 'Error: api key expired'], 'signing secret'],
	]);

	test('a live key is never handed to the Stripe CLI, even to ask for a secret', function (): void {
		bootSiteForStripeListen(['testSecretKey' => 'sk_live_real_money'] + STRIPE_LISTEN_SETTINGS);
		$cli = stripeCliStandIn();

		$tester = stripeListen($cli);

		expect($cli->askedWith)->toBe([])
			->and($tester->getDisplay())->not->toContain('sk_live_real_money');
	});

	test('a restricted test key is a test key', function (): void {
		bootSiteForStripeListen(['testSecretKey' => 'rk_test_restricted'] + STRIPE_LISTEN_SETTINGS);
		$cli = stripeCliStandIn();

		expect(stripeListen($cli)->getStatusCode())->toBe(Command::SUCCESS)
			->and($cli->listened[0][0])->toBe('rk_test_restricted');
	});
});

test('a store that has not chosen Stripe is told so, and still listens', function (): void {
	bootSiteForStripeListen();
	$config           = $this->c->get(Config::class);
	$config->commerce = ['providers' => []] + $config->commerce;
	$cli              = stripeCliStandIn();

	$tester = stripeListen($cli);

	expect($tester->getDisplay())->toContain('Payment Providers')
		->and($cli->listened)->toHaveCount(1);
});

describe('the extension', function (): void {
	test('gives the tcms command line stripe:listen', function (): void {
		bootSiteForStripeListen();

		$names = array_map(fn (Command $command): ?string => $command->getName(), $this->c->get(ExtensionManager::class)->getAllCommands());

		expect($names)->toContain('stripe:listen');
	});

	test('keeps it off the command line where its commands are switched off', function (): void {
		bootSiteForStripeListen(STRIPE_LISTEN_SETTINGS, false);

		$names = array_map(fn (Command $command): ?string => $command->getName(), $this->c->get(ExtensionManager::class)->getAllCommands());

		expect($names)->not->toContain('stripe:listen');
	});
});

describe('the Stripe CLI itself', function (): void {
	beforeEach(function (): void {
		bootSiteForStripeListen();
		$this->bin = sys_get_temp_dir() . '/tcms-stripe-cli-' . bin2hex(random_bytes(4));
		mkdir($this->bin);
	});

	afterEach(function (): void {
		array_map(unlink(...), glob($this->bin . '/*') ?: []);
		rmdir($this->bin);
	});

	test('is installed when a program called stripe is on the path', function (): void {
		expect((new StripeCli($this->bin))->installed())->toBeFalse();

		file_put_contents($this->bin . '/stripe', "#!/bin/sh\nexit 0\n");
		chmod($this->bin . '/stripe', 0755);

		expect((new StripeCli('/nowhere-at-all' . PATH_SEPARATOR . $this->bin))->installed())->toBeTrue();
	});

	test('is told which events to forward and where, and is never given the key as an argument', function (): void {
		$cli = new StripeCli($this->bin);

		expect($cli->listenArguments('https://shop.test/commerce/webhook/stripe', false))->toBe(['listen', '--all-snapshot', '--latest', '--forward-to', 'https://shop.test/commerce/webhook/stripe'])
			->and($cli->listenArguments('https://shop.test/commerce/webhook/stripe', true))->toBe(['listen', '--all-snapshot', '--latest', '--forward-to', 'https://shop.test/commerce/webhook/stripe', '--skip-verify']);
	});

	// An event is formatted in the API version of whoever receives it, and
	// the Stripe CLI's default is the account's own, which on an old account
	// is years behind the version the store is written for.
	test('asks for events in the current API version, whatever the account\'s own is', function (): void {
		expect((new StripeCli($this->bin))->listenArguments('https://shop.test/hook', false))->toContain('--latest');
	});

	// A program that prints what it was given stands in for the Stripe CLI.
	test('asks for the signing secret with the key in the environment, not on the command line', function (): void {
		file_put_contents($this->bin . '/stripe', "#!/bin/sh\necho \"whsec_\$STRIPE_API_KEY|\$*\"\n");
		chmod($this->bin . '/stripe', 0755);

		expect((new StripeCli($this->bin))->signingSecret('sk_test_abc'))->toBe('whsec_sk_test_abc|listen --print-secret');
	})->skip(PHP_OS_FAMILY === 'Windows', 'a shell script stands in for the Stripe CLI');

	// When it starts, the Stripe CLI says "Your webhook signing secret is whsec_…".
	test('shows what the program prints as it listens, with its signing secret hidden, and ends as it ended', function (): void {
		file_put_contents($this->bin . '/stripe', "#!/bin/sh\necho \"Ready! Your webhook signing secret is whsec_abc123DEF (^C to quit)\"\nfor a in \"\$@\"; do last=\$a; done\necho \"--> charge.succeeded [evt_1] to \$last\" >&2\nprintf 'no newline at the end'\nexit 4\n");
		chmod($this->bin . '/stripe', 0755);
		$shown = '';

		$exit = (new StripeCli($this->bin))->listen('sk_test_abc', 'https://shop.test/hook', false, function (string $text) use (&$shown): void {
			$shown .= $text;
		});

		expect($exit)->toBe(4)
			->and($shown)->toContain('Ready! Your webhook signing secret is whsec_… (hidden) (^C to quit)', '--> charge.succeeded [evt_1] to https://shop.test/hook', 'no newline at the end')
			->not->toContain('whsec_abc123DEF');
	})->skip(PHP_OS_FAMILY === 'Windows', 'a shell script stands in for the Stripe CLI');

	test('a secret is hidden wherever it is in a line', function (): void {
		expect(StripeCli::hideSecrets('a whsec_1a2B3c b whsec_9z'))->toBe('a whsec_… (hidden) b whsec_… (hidden)')
			->and(StripeCli::hideSecrets('key sk_test_51AbC and rk_test_9zZ'))->toBe('key sk_test_… (hidden) and rk_test_… (hidden)')
			->and(StripeCli::hideSecrets('nothing to hide'))->toBe('nothing to hide');
	});

	test('gives no secret when the Stripe CLI fails or is not there', function (): void {
		expect((new StripeCli($this->bin))->signingSecret('sk_test_abc'))->toBe('');

		file_put_contents($this->bin . '/stripe', "#!/bin/sh\necho 'whsec_half'\nexit 1\n");
		chmod($this->bin . '/stripe', 0755);

		expect((new StripeCli($this->bin))->signingSecret('sk_test_abc'))->toBe('');
	})->skip(PHP_OS_FAMILY === 'Windows', 'a shell script stands in for the Stripe CLI');
});
