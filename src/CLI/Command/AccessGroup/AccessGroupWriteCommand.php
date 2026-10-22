<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\AccessGroup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupEditor;
use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * What access-group:set and access-group:patch share: read the JSON, check
 * the edition (access groups are an edition feature, as on the admin page),
 * save through AccessGroupEditor and report.
 */
abstract class AccessGroupWriteCommand extends BaseCommand
{
	/**
	 * Save $data to group $id through $editor.
	 *
	 * @param array<string,mixed> $data
	 */
	abstract protected function write(AccessGroupEditor $editor, string $id, array $data): AccessGroupData;

	abstract protected function verb(): string;

	protected function configure(): void
	{
		parent::configure();
		$this
			->addArgument('id', InputArgument::REQUIRED, 'Access group ID')
			->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON file, or "-" for stdin');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->totalcms->editionFeatures()->can(EditionFeature::ACCESS_GROUPS)) {
			return $this->outputError($input, $output, 'Access Groups are not available on this license edition.');
		}

		$id   = (string)$input->getArgument('id');
		$data = $this->readJsonObject($input, $output, (string)$input->getArgument('file'), 'Input is a JSON array — an access group is one JSON object.');
		if ($data === null) {
			return Command::FAILURE;
		}

		try {
			$group = $this->write($this->totalcms->container()->get(AccessGroupEditor::class), $id, $data);
		} catch (\DomainException|\RuntimeException $e) {
			return $this->outputError($input, $output, "Access group not saved: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			return $this->outputData($input, $output, $group->toArray());
		}

		$output->writeln(sprintf("<info>%s access group '%s'.</info>", $this->verb(), $id));
		$output->writeln('  Content: ' . AccessGroupFormat::reach((array)($group->permissions['collections'] ?? [])));

		return Command::SUCCESS;
	}
}
