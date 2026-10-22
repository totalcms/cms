<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\AccessGroup;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupEditor;

/**
 * Change part of an existing access group.
 */
class AccessGroupPatchCommand extends AccessGroupWriteCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('access-group:patch')
			->setDescription('Change part of an existing access group from JSON (use "-" to read stdin)')
			->setHelp(<<<'HELP'
Change part of an existing access group, keeping the rest. Each permission
section merges key by key, so send only what changes:

  <info>echo '{"permissions":{"collections":{"allowed":["blog","news"]}}}' | tcms access-group:patch editor -</info>
  <info>echo '{"permissions":{"dataviews":true}}' | tcms access-group:patch analysts -</info>
  <info>echo '{"description":"Writes and edits posts"}' | tcms access-group:patch editor -</info>

A list (operations, allowed) is replaced by the list sent. See
<info>access-group:set --help</info> for the full shape.
HELP);
	}

	protected function write(AccessGroupEditor $editor, string $id, array $data): AccessGroupData
	{
		if ($data === []) {
			throw new \DomainException('Nothing to change: the JSON object is empty.');
		}

		return $editor->patch($id, $data);
	}

	protected function verb(): string
	{
		return 'Updated';
	}
}
