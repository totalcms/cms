<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;

class BuilderRoutesCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('builder:routes')
			->setDescription('List every route the page router would serve, with conflicts flagged');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$builderConfig    = $this->totalcms->container()->get(BuilderConfigService::class);
		$indexReader      = $this->totalcms->indexReader();
		$collectionLister = $this->totalcms->collectionLister();

		$rows = [];

		// Builder pages
		if ($builderConfig->pagesCollectionExists()) {
			try {
				$index = $indexReader->fetchIndex($builderConfig->getPagesCollectionId());
				foreach ($index->objects as $page) {
					$route = (string)($page['route'] ?? '');
					if ($route === '') {
						continue;
					}
					$rows[] = [
						'route'    => $route,
						'source'   => 'page',
						'id'       => (string)($page['id'] ?? ''),
						'template' => (string)($page['template'] ?? ''),
						'status'   => (int)($page['status'] ?? 200),
						'draft'    => (bool)($page['draft'] ?? false),
					];
				}
			} catch (\Throwable) {
				// No index yet — skip
			}
		}

		// Collection URL patterns. Display the EFFECTIVE matched route, not the
		// stored url — a collection with url `/blog` actually matches
		// `/blog/{id}` requests (the router appends an id segment), and a
		// templated url like `/blog/{{ id }}` is normalised to `/blog/{id}`
		// for display consistency.
		foreach ($collectionLister->listAllCollections() as $collection) {
			if ($collection->url === '' || !$collection->prettyUrl) {
				continue;
			}
			$rows[] = [
				'route'    => $this->effectiveCollectionRoute($collection->url),
				'source'   => 'collection',
				'id'       => $collection->id,
				'template' => 'pages/' . $collection->id . '.twig',
				'status'   => 200,
				'draft'    => false,
			];
		}

		// Detect duplicate routes (same exact pattern declared in two places)
		$conflictMap = [];
		foreach ($rows as $i => $row) {
			$conflictMap[$row['route']][] = $i;
		}
		foreach ($rows as &$row) {
			$row['conflict'] = count($conflictMap[$row['route']]) > 1;
		}
		unset($row);

		// Sort: drafts last, then by route
		usort($rows, function (array $a, array $b): int {
			if ($a['draft'] !== $b['draft']) {
				return $a['draft'] <=> $b['draft'];
			}

			return strcmp($a['route'], $b['route']);
		});

		return $this->outputData($input, $output, $rows);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data === []) {
			$output->writeln('No routes registered.');

			return;
		}

		$tableRows  = [];
		$conflicts  = 0;
		foreach ($data as $row) {
			if (!is_array($row)) {
				continue;
			}
			$flag = '';
			if (!empty($row['conflict'])) {
				$flag      = '⚠ duplicate';
				$conflicts++;
			} elseif (!empty($row['draft'])) {
				$flag = 'draft';
			}

			$tableRows[] = [
				(string)($row['route'] ?? ''),
				(string)($row['source'] ?? ''),
				(string)($row['id'] ?? ''),
				(string)($row['template'] ?? ''),
				(string)($row['status'] ?? 200),
				$flag,
			];
		}

		TableHelper::renderList(
			$output,
			['Route', 'Source', 'ID', 'Template', 'Status', 'Notes'],
			$tableRows,
		);

		if ($conflicts > 0) {
			$output->writeln('');
			$output->writeln("<comment>{$conflicts} duplicate route(s) detected.</comment>");
		}
	}

	/**
	 * Compute the effective route pattern a collection URL actually matches.
	 *
	 *   `/blog`              → `/blog/{id}`        (router appends an id segment)
	 *   `/blog/{{ id }}`     → `/blog/{id}`        (Twig syntax → standard)
	 *   `/products/{{ category }}/{{ id }}` → `/products/{category}/{id}`
	 */
	private function effectiveCollectionRoute(string $url): string
	{
		// Normalize Twig variable syntax `{{ name }}` to `{name}` for display
		$normalized = (string)preg_replace('/\{\{\s*(\w+)\s*\}\}/', '{$1}', $url);

		// If the pattern has no placeholder at all, the router appends `/{id}`
		// implicitly when matching, so the effective route is `/<url>/{id}`.
		if (!str_contains($normalized, '{')) {
			$normalized = rtrim($normalized, '/') . '/{id}';
		}

		return $normalized;
	}
}
