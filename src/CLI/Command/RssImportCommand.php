<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Queue an RSS/Atom/JSON Feed for import — the CLI counterpart to the admin
 * Utilities → Import RSS page.
 *
 * Intended for cron: the admin form has a "Schedule with cron" panel that
 * builds the exact command line for the configured import (URL, collection,
 * field map, draft flag) so operators can paste it directly into crontab.
 */
class RssImportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('rss:import')
			->setDescription('Import an RSS/Atom/JSON feed into a collection')
			->setHelp(
				<<<HELP
				Fetch a feed and queue each entry for import into the target collection.
				Items land in the job queue; run `tcms jobs:process` to drain it (or wait
				for an existing scheduled run).

				Examples:

				  # Basic import — auto field mapping, items queued as drafts
				  tcms rss:import https://example.com/feed.xml blog

				  # Publish immediately (no draft)
				  tcms rss:import https://example.com/feed.xml blog --no-draft

				  # Override field mappings (feed-field=collection-field)
				  tcms rss:import https://example.com/feed.xml blog \
				    --map title=heading \
				    --map content=body \
				    --map image=featured

				  # Drain the queue in the same cron run
				  tcms rss:import https://example.com/feed.xml blog && tcms jobs:process

				Recognized feed-side fields for `--map`:
				  title, content, summary, date, author, categories, link, image
				HELP,
			)
			->addArgument('url', InputArgument::REQUIRED, 'RSS/Atom/JSON feed URL')
			->addArgument('collection', InputArgument::REQUIRED, 'Target collection ID')
			->addOption('draft', null, InputOption::VALUE_NEGATABLE, 'Queue items as drafts (use --no-draft to publish immediately)', true)
			->addOption('map', 'm', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Field mapping in form feedField=collectionField. Repeat for multiple, or comma-separate within one value.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$url        = trim((string)$input->getArgument('url'));
		$collection = trim((string)$input->getArgument('collection'));
		$isDraft    = (bool)$input->getOption('draft');

		if (!$this->totalcms->editionFeatures()->can(EditionFeature::RSS_IMPORT)) {
			return $this->outputError($input, $output, 'RSS Import is not available on this license edition.');
		}

		if (!$this->totalcms->collectionFetcher()->collectionExists($collection)) {
			return $this->outputError($input, $output, "Collection '{$collection}' does not exist.");
		}

		try {
			$fieldMap = $this->parseFieldMap((array)$input->getOption('map'));
		} catch (\InvalidArgumentException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		try {
			$importCount = $this->totalcms->rssImporter()->import($url, $collection, [
				'draft'    => $isDraft,
				'fieldMap' => $fieldMap,
			]);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Import failed: {$e->getMessage()}");
		}

		return $this->outputData($input, $output, [
			'success'    => true,
			'imported'   => $importCount,
			'url'        => $url,
			'collection' => $collection,
			'draft'      => $isDraft,
			'fieldMap'   => $fieldMap,
		]);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$count      = (int)($data['imported'] ?? 0);
		$collection = (string)($data['collection'] ?? '');

		$output->writeln("<info>Queued {$count} item(s) for import into '{$collection}'.</info>");
		$output->writeln('<comment>Run `tcms jobs:process` to drain the queue.</comment>');
	}

	/**
	 * Parse repeatable / CSV `--map` values into a flat feedField=>collectionField map.
	 *
	 * Accepts either `--map title=heading --map content=body` (repeated) or
	 * `--map "title=heading,content=body"` (CSV inside one value), or any mix.
	 *
	 * @param array<int,mixed> $rawValues
	 *
	 * @return array<string,string>
	 */
	private function parseFieldMap(array $rawValues): array
	{
		$map = [];

		foreach ($rawValues as $raw) {
			if (!is_string($raw)) {
				continue;
			}

			foreach (explode(',', $raw) as $pair) {
				$pair = trim($pair);
				if ($pair === '') {
					continue;
				}
				if (!str_contains($pair, '=')) {
					throw new \InvalidArgumentException("Invalid --map value '{$pair}'. Expected feedField=collectionField.");
				}
				[$feedField, $collectionField] = explode('=', $pair, 2);
				$feedField                     = trim($feedField);
				$collectionField               = trim($collectionField);
				if ($feedField === '') {
					throw new \InvalidArgumentException("Invalid --map value '{$pair}': empty feed field.");
				}
				$map[$feedField] = $collectionField;
			}
		}

		return $map;
	}
}
