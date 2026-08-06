<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\OAuth;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthClientPruner;

class OAuthGcCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('oauth:gc')
			->setDescription('Prune expired OAuth grants and stale self-registered clients');
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

		$pruner        = $this->totalcms->container()->get(OAuthClientPruner::class);
		$prunedClients = $pruner->pruneStaleDynamicClients();

		$output->writeln(sprintf(
			'<info>Pruned %d stale dynamic client%s.</info>',
			count($prunedClients),
			count($prunedClients) === 1 ? '' : 's',
		));
		foreach ($prunedClients as $client) {
			$output->writeln(sprintf('  - %s (%s)', $client->name, $client->id));
		}

		return 0;
	}
}
