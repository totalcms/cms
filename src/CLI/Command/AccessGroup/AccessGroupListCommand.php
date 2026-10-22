<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\AccessGroup;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;

/**
 * List the site's access groups. Access groups live in
 * `.system/access-groups.json`, not in a collection, so the collection
 * commands do not reach them.
 */
class AccessGroupListCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('access-group:list')
			->setDescription('List access groups and what each grants on collections');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$groups = array_map(
			static fn (AccessGroupData $group): array => $group->toArray(),
			$this->totalcms->container()->get(AccessGroupLister::class)->listAll(),
		);

		return $this->outputData($input, $output, array_values($groups));
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		if ($data === []) {
			$output->writeln('No access groups found.');

			return;
		}

		$rows = [];
		foreach ($data as $group) {
			$collections = (array)($group['permissions']['collections'] ?? []);
			$rows[]      = [
				(string)($group['id'] ?? ''),
				(string)($group['description'] ?? ''),
				AccessGroupFormat::reach($collections),
			];
		}

		TableHelper::renderList($output, ['ID', 'Description', 'Collections'], $rows);
	}
}
