<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\AccessGroup;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;

/**
 * Show one access group: its full definition with --json, the shape
 * access-group:set and access-group:patch take.
 */
class AccessGroupGetCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('access-group:get')
			->setDescription('Show one access group (--json gives the definition access-group:set takes)')
			->addArgument('id', InputArgument::REQUIRED, 'Access group ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id    = (string)$input->getArgument('id');
		$group = $this->totalcms->container()->get(AccessGroupLister::class)->findById($id);
		if (!$group instanceof AccessGroupData) {
			return $this->outputError($input, $output, "Access group '{$id}' not found. Use access-group:list to see the groups.");
		}

		return $this->outputData($input, $output, $group->toArray());
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$permissions = (array)($data['permissions'] ?? []);

		$output->writeln('<info>' . ($data['id'] ?? '') . '</info>');
		if (($data['description'] ?? '') !== '') {
			$output->writeln('  ' . $data['description']);
		}
		foreach (['collections' => 'Content', 'collectionsMeta' => 'Collection settings', 'schemas' => 'Schemas'] as $section => $label) {
			$output->writeln(sprintf('  %-20s %s', $label . ':', AccessGroupFormat::reach((array)($permissions[$section] ?? []))));
		}
		foreach (['utils' => 'Utilities', 'settings' => 'Settings', 'extensions' => 'Extensions'] as $section => $label) {
			$value   = (array)($permissions[$section] ?? []);
			$allowed = array_filter((array)($value['allowed'] ?? []), is_string(...));
			$where   = ($value['all'] ?? false) === true ? 'all' : ($allowed === [] ? 'none' : implode(', ', $allowed));
			$output->writeln(sprintf('  %-20s %s', $label . ':', $where));
		}

		$flags = array_keys(array_filter($permissions, static fn (mixed $value): bool => $value === true));
		$output->writeln(sprintf('  %-20s %s', 'Also:', $flags === [] ? 'nothing' : implode(', ', $flags)));
	}
}
