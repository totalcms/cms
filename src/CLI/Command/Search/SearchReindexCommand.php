<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Search;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Support\Config;

class SearchReindexCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('search:reindex')
			->setDescription('Re-index objects against the active search provider')
			->addArgument('collection', InputArgument::OPTIONAL, 'Collection id (omit + use --all for site-wide)')
			->addOption('all', null, InputOption::VALUE_NONE, 'Reindex every collection on the site');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$container = $this->totalcms->container();
		$config    = $container->get(Config::class);
		$activeId  = (string)($config->search['activeProvider'] ?? 'text');

		if ($activeId === 'text') {
			$output->writeln('<comment>Active provider is "text" — nothing to reindex (IndexBuilder maintains the text index).</comment>');
			return 0;
		}

		$registry = $container->get(SearchProviderRegistry::class);
		$provider = $registry->active($activeId);
		if ($provider === null) {
			$output->writeln(sprintf('<error>Active provider "%s" is not registered.</error>', $activeId));
			return 1;
		}

		$collectionArg = (string)($input->getArgument('collection') ?? '');
		$all           = (bool)$input->getOption('all');

		if ($collectionArg === '' && !$all) {
			$output->writeln('<error>Specify a collection id or pass --all.</error>');
			return 1;
		}

		$collections = $container->get(CollectionRepository::class);
		$jobs        = $container->get(JobQueuer::class);
		$reader      = $container->get(IndexReader::class);

		$ids = $all
			? array_map(static fn ($c) => $c->id, $collections->listAllCollections())
			: [$collectionArg];

		$totalQueued = 0;
		foreach ($ids as $cid) {
			$indexData = $reader->fetchIndex($cid);
			$count     = 0;
			foreach ($indexData->objects as $object) {
				$objectId = (string)($object['id'] ?? '');
				if ($objectId === '') {
					continue;
				}
				$jobs->queueJob(JobData::TYPE_SEARCH_REINDEX, $cid, [
					'object_id' => $objectId,
					'operation' => 'index',
				]);
				$count++;
			}
			$output->writeln(sprintf('  %s: %d objects queued', $cid, $count));
			$totalQueued += $count;
		}

		$output->writeln('');
		$output->writeln(sprintf('<info>Queued %d ReindexJob entries to JobQueue for provider "%s".</info>', $totalQueued, $activeId));
		$output->writeln('<comment>Process the queue: php resources/bin/tcms jobs:process</comment>');

		return 0;
	}
}
