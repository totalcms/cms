<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\OAuth;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;

class OAuthGcCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('oauth:gc')
			->setDescription('Prune expired OAuth grants from storage');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$editionFeatures = $this->totalcms->container()->get(EditionFeatureService::class);
		if (!$editionFeatures->can(EditionFeature::OAUTH_SERVER)) {
			$required = EditionFeature::OAUTH_SERVER->requiredEdition();
			$output->writeln(sprintf(
				'<error>OAuth server requires the %s edition or higher.</error>',
				ucfirst($required->value),
			));

			return 1;
		}

		$grants  = $this->totalcms->container()->get(OAuthGrantRepository::class);
		$removed = $grants->pruneExpired();

		$output->writeln(sprintf(
			'<info>Pruned %d expired OAuth grant%s.</info>',
			$removed,
			$removed === 1 ? '' : 's',
		));

		return 0;
	}
}
