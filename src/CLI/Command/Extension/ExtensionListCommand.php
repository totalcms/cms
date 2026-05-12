<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Extension;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;

class ExtensionListCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('extension:list')
			->setDescription('List all discovered extensions');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$discovery = $this->totalcms->container()->get(ExtensionDiscovery::class);
		$stateRepo = $this->totalcms->container()->get(ExtensionStateRepository::class);

		$manifests = $discovery->discover();
		$states    = $stateRepo->loadAll();

		$data = [];
		foreach ($manifests as $id => $manifest) {
			$state  = $states[$id] ?? null;
			$data[] = [
				'id'      => $id,
				'name'    => $manifest->name,
				'version' => $manifest->version,
				'enabled' => $state !== null && $state->enabled,
				'error'   => $state?->error,
			];
		}

		return $this->outputData($input, $output, $data);
	}

	/** @param array<string,mixed>|list<mixed> $data */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data === []) {
			$output->writeln('No extensions found.');

			return;
		}

		$rows = [];
		foreach ($data as $ext) {
			$status = $ext['enabled'] ? '<info>enabled</info>' : '<comment>disabled</comment>';
			if ($ext['error'] !== null) {
				$status = '<error>error</error>';
			}

			$rows[] = [
				$ext['id'],
				$ext['version'],
				$status,
				$ext['error'] ?? '',
			];
		}

		TableHelper::renderList($output, ['ID', 'Version', 'Status', 'Error'], $rows);
	}
}
