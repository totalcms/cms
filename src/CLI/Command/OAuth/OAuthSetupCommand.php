<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\OAuth;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Support\Config;

class OAuthSetupCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('oauth:setup')
			->setDescription('Generate OAuth signing key pair')
			->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate keys even if they already exist');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$editionFeatures = $this->totalcms->container()->get(EditionFeatureService::class);
		if (!$editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			$required = EditionFeature::OAUTH_SERVER->requiredEdition();
			$output->writeln(sprintf(
				'<error>OAuth server requires the %s edition or higher. Cannot generate keys on the current edition.</error>',
				ucfirst($required->value),
			));

			return 1;
		}

		$config      = $this->totalcms->container()->get(Config::class);
		$privatePath = (string)($config->oauth['signingKeyPath'] ?? '');
		$publicPath  = (string)($config->oauth['publicKeyPath'] ?? '');
		$force       = (bool)$input->getOption('force');

		if ($privatePath === '' || $publicPath === '') {
			$output->writeln('<error>OAuth key paths not configured.</error>');

			return 1;
		}

		if (!$force && is_file($privatePath) && is_file($publicPath)) {
			$output->writeln(sprintf('<info>OAuth signing keys already configured at %s.</info>', dirname($privatePath)));
			$output->writeln('Pass <comment>--force</comment> to regenerate (will invalidate existing tokens).');

			return 0;
		}

		$dir = dirname($privatePath);
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			$output->writeln(sprintf('<error>Failed to create directory %s</error>', $dir));

			return 1;
		}

		$resource = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);

		if ($resource === false) {
			$output->writeln('<error>openssl_pkey_new() failed — is the openssl extension loaded?</error>');

			return 1;
		}

		openssl_pkey_export($resource, $privatePem);

		$details = openssl_pkey_get_details($resource);
		if (!is_array($details) || !isset($details['key'])) {
			$output->writeln('<error>Failed to extract public key from generated resource</error>');

			return 1;
		}

		file_put_contents($privatePath, (string)$privatePem);
		file_put_contents($publicPath, (string)$details['key']);

		if (!@chmod($privatePath, 0600)) {
			$output->writeln(sprintf('<comment>Warning: could not set 0600 permissions on %s</comment>', $privatePath));
		}

		$output->writeln('');
		$output->writeln('<info>OAuth signing key pair generated:</info>');
		$output->writeln('  Private: ' . $privatePath);
		$output->writeln('  Public:  ' . $publicPath);
		$output->writeln('');
		$output->writeln('To rotate later, run <comment>tcms oauth:setup --force</comment> (will invalidate existing tokens).');
		$output->writeln('');

		return 0;
	}

}
